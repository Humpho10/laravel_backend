<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductManagerAssignment;
use App\Models\Settings;
use App\Models\User;
use App\Helpers\AuditLogger;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    /**
     * @var \App\Models\User|null
     */
    protected $user;

    /**
     * Constructor – set the authenticated user once.
     */
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            /** @var \App\Models\User $user */
            $this->user = $request->user();
            return $next($request);
        });
    }

    // ── All conversations for logged in user ──────────────────
    public function index()
    {
        if (!$this->user->can('message-view')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view messages.'], 403);
        }

        // Get all unique conversations, grouped by product + the other
        // participant — a product can have more than one distinct thread
        // (e.g. a buyer↔seller thread and a seller↔PM thread).
        $messages = Message::with([
            'sender:id,name,email',
            'recipient:id,name,email',
            'product:product_id,title',
        ])
        ->where(function($q) {
            $q->where('sender_id',    Auth::id())
              ->orWhere('recipient_id', Auth::id());
        })
        ->latest()
        ->get()
        ->groupBy(fn ($m) => ($m->product_id ?? 'staff') . '-' . ($m->sender_id === Auth::id() ? $m->recipient_id : $m->sender_id))
        ->map(function($group) {
            $latest = $group->first();
            return [
                'product_id'    => $latest->product_id,
                'product_title' => $latest->product?->title,
                'last_message'  => $latest->message_text,
                'last_message_at' => $latest->created_at,
                'unread_count'  => $group->where('recipient_id', Auth::id())
                                         ->where('is_read', false)
                                         ->count(),
                'other_person'  => $latest->sender_id === Auth::id()
                                    ? $latest->recipient
                                    : $latest->sender,
            ];
        })->values();

        return response()->json([
            'conversations' => $messages,
            'count'         => $messages->count(),
        ], 200);
    }

    // ── Messages with a specific counterpart, optionally scoped to a
    // specific product — omitting product_id fetches the general "staff"
    // thread with that person instead of a listing-specific one.
    public function show(int $otherId, Request $request)
    {
        if (!$this->user->can('message-view')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view messages.'], 403);
        }

        $productId = $request->query('product_id');
        $product   = $productId ? Product::findOrFail($productId) : null;

        $scopeToProduct = function ($query) use ($product) {
            return $product
                ? $query->where('product_id', $product->product_id)
                : $query->whereNull('product_id');
        };

        $messages = $scopeToProduct(
            Message::with(['sender:id,name,email', 'recipient:id,name,email'])
        )
        ->where(function($q) use ($otherId) {
            $q->where(fn($q2) => $q2->where('sender_id', Auth::id())->where('recipient_id', $otherId))
              ->orWhere(fn($q2) => $q2->where('sender_id', $otherId)->where('recipient_id', Auth::id()));
        })
        ->oldest()
        ->get();

        // Mark this counterpart's messages to me as read
        $scopeToProduct(Message::query())
               ->where('sender_id',    $otherId)
               ->where('recipient_id', Auth::id())
               ->where('is_read',      false)
               ->update(['is_read' => true]);

        return response()->json([
            'product'  => $product ? [
                'id'    => $product->product_id,
                'title' => $product->title,
            ] : null,
            'messages' => $messages,
            'count'    => $messages->count(),
        ], 200);
    }

    // ── Send a message ────────────────────────────────────────
    public function send(Request $request)
    {
        if (!$this->user->can('message-send')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to send messages.'], 403);
        }

        $validated = $request->validate([
            'product_id'   => 'nullable|exists:products,product_id',
            'message_text' => 'required|string|min:1|max:2000',
            'recipient_id' => 'nullable|exists:users,id',
        ]);

        $product = !empty($validated['product_id']) ? Product::findOrFail($validated['product_id']) : null;

        if (!empty($validated['recipient_id'])) {
            $recipientId = (int) $validated['recipient_id'];

            if ($recipientId === Auth::id()) {
                return response()->json(['error' => 'You cannot message yourself.'], 400);
            }

            if ($product) {
                if (!$this->isValidRecipient($product, $recipientId)) {
                    return response()->json(['error' => 'That recipient is not associated with this listing.'], 403);
                }
            } elseif (!$this->isValidStaffRecipient($this->user, $recipientId)) {
                return response()->json(['error' => 'You are not able to message this user.'], 403);
            }
        } elseif ($product && Auth::id() !== $product->seller_id) {
            // Buyer messaging — recipient is always the seller
            $recipientId = $product->seller_id;
        } elseif ($product) {
            // Seller replying — find the buyer who last messaged them
            $lastMessage = Message::where('product_id', $product->product_id)
                ->where('sender_id', '!=', Auth::id())
                ->latest()
                ->first();

            if (!$lastMessage) {
                return response()->json([
                    'error' => 'No one has messaged you about this listing yet.'
                ], 400);
            }

            $recipientId = $lastMessage->sender_id;
        } else {
            return response()->json(['error' => 'A recipient is required.'], 400);
        }

        $message = Message::create([
            'product_id'   => $product?->product_id,
            'sender_id'    => Auth::id(),
            'recipient_id' => $recipientId,
            'message_text' => $validated['message_text'],
            'is_read'      => false,
        ]);

        // Notify recipient
        Notification::create([
            'user_id'      => $recipientId,
            'type'         => 'new_message',
            'reference_id' => $product?->product_id,
            'message'      => $product
                ? "You have a new message regarding \"{$product->title}\"."
                : "You have a new message from {$this->user->name}.",
            'is_read'      => false,
        ]);

        // Optional oversight ping — lets Admins/Super Admins keep an eye on
        // buyer-seller conversations without joining every thread.
        if ($product && Settings::current()->notify_admins_on_new_message) {
            User::role(['Admin', 'Super-Admin'])->get()->each(function ($admin) use ($product) {
                Notification::create([
                    'user_id'      => $admin->id,
                    'type'         => 'new_message_oversight',
                    'reference_id' => $product->product_id,
                    'message'      => "New message activity on \"{$product->title}\".",
                    'is_read'      => false,
                ]);
            });
        }

        return response()->json([
            'message'      => 'Message sent successfully.',
            'sent_message' => $message->load([
                'sender:id,name,email',
                'recipient:id,name,email',
            ]),
        ], 201);
    }

    // A recipient is valid if they're the seller, the Product Manager
    // assigned to this product's category, or someone who already has
    // message history with the current user on this product (e.g. a
    // seller or buyer replying to a PM, or a PM replying to either).
    private function isValidRecipient(Product $product, int $recipientId): bool
    {
        if ($recipientId === $product->seller_id) {
            return true;
        }

        $pmId = ProductManagerAssignment::where('category_id', $product->category_id)
            ->value('product_manager_id');

        if ($pmId && $recipientId === $pmId) {
            return true;
        }

        return Message::where('product_id', $product->product_id)
            ->where(function ($q) use ($recipientId) {
                $q->where('sender_id', $recipientId)->orWhere('recipient_id', $recipientId);
            })
            ->exists();
    }

    // Staff conversations (no product): Product Manager <-> Admin, and
    // Admin <-> Super Admin. Symmetric both ways so replies work.
    private function isValidStaffRecipient(User $sender, int $recipientId): bool
    {
        $recipient = User::find($recipientId);
        if (!$recipient) {
            return false;
        }

        $pair = fn ($a, $b) => $sender->hasRole($a) && $recipient->hasRole($b);

        return $pair('Admin', 'Product-Manager')
            || $pair('Product-Manager', 'Admin')
            || $pair('Admin', 'Super-Admin')
            || $pair('Super-Admin', 'Admin');
    }

    // ── Unread message count ──────────────────────────────────
    public function unreadCount()
    {
        if (!$this->user->can('message-view')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view messages.'], 403);
        }

        $count = Message::where('recipient_id', Auth::id())
            ->where('is_read', false)
            ->count();

        return response()->json([
            'unread_count' => $count
        ], 200);
    }
}