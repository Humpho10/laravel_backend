<?php

namespace App\Http\Controllers;

use App\Helpers\AuditLogger;
use App\Models\Notification;
use App\Models\StaffMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Internal staff chat — Super Admin ↔ Admin / Product-Manager only.
 *
 * The Super Admin isn't a buyer or seller and no longer has any visibility
 * into buyer/seller conversations (see MessageController) — this is their
 * only messaging surface, and it's strictly scoped to the two staff roles.
 * Admins and Product-Managers use the same endpoints to see and reply to
 * messages from the Super Admin.
 */
class StaffMessageController extends Controller
{
    /** @var \App\Models\User */
    protected $user;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->user = $request->user();
            return $next($request);
        });
    }

    /**
     * Is the given "other" user someone the current user is allowed to
     * exchange staff messages with?
     */
    private function isAllowedContact(User $other): bool
    {
        if ($this->user->hasRole('Super-Admin')) {
            return $other->hasRole('Admin') || $other->hasRole('Product-Manager');
        }

        if ($this->user->hasRole('Admin') || $this->user->hasRole('Product-Manager')) {
            return $other->hasRole('Super-Admin');
        }

        return false;
    }

    // ── Contacts the current user is allowed to message ────────
    public function contacts(Request $request)
    {
        if ($this->user->hasRole('Super-Admin')) {
            $query = User::role(['Admin', 'Product-Manager'])->with('roles');
        } elseif ($this->user->hasRole('Admin') || $this->user->hasRole('Product-Manager')) {
            $query = User::role('Super-Admin')->with('roles');
        } else {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role') && in_array($request->role, ['Admin', 'Product-Manager'])) {
            $query->role($request->role);
        }

        $contacts = $query->orderBy('name')->get()->map(fn ($u) => [
            'id'    => $u->id,
            'name'  => $u->name,
            'email' => $u->email,
            'role'  => $u->roles->first()?->name,
            'avatar'=> $u->avatar,
        ]);

        return response()->json(['contacts' => $contacts]);
    }

    // ── This user's staff conversations, grouped by the other party ─
    public function conversations()
    {
        $messages = StaffMessage::with(['sender:id,name,email', 'recipient:id,name,email'])
            ->where(fn ($q) => $q->where('sender_id', Auth::id())->orWhere('recipient_id', Auth::id()))
            ->latest()
            ->get();

        $conversations = $messages
            ->groupBy(fn ($m) => $m->sender_id === Auth::id() ? $m->recipient_id : $m->sender_id)
            ->map(function ($group) {
                $latest = $group->first();
                $other  = $latest->sender_id === Auth::id() ? $latest->recipient : $latest->sender;
                return [
                    'user_id'         => $other?->id,
                    'name'            => $other?->name,
                    'email'           => $other?->email,
                    'last_message'    => $latest->message_text,
                    'last_message_at' => $latest->created_at,
                    'unread_count'    => $group->where('recipient_id', Auth::id())->where('is_read', false)->count(),
                ];
            })
            ->filter(fn ($c) => $c['user_id'] !== null)
            ->sortByDesc('last_message_at')
            ->values();

        return response()->json([
            'conversations' => $conversations,
            'total_unread'  => StaffMessage::where('recipient_id', Auth::id())->where('is_read', false)->count(),
        ]);
    }

    // ── Thread with one specific staff member ───────────────────
    public function thread($userId)
    {
        $other = User::find($userId);
        if (!$other || !$this->isAllowedContact($other)) {
            return response()->json(['message' => 'You are not allowed to message this user.'], 403);
        }

        $messages = StaffMessage::with(['sender:id,name,email', 'recipient:id,name,email'])
            ->where(function ($q) use ($userId) {
                $q->where('sender_id', Auth::id())->where('recipient_id', $userId);
            })
            ->orWhere(function ($q) use ($userId) {
                $q->where('sender_id', $userId)->where('recipient_id', Auth::id());
            })
            ->oldest()
            ->get();

        StaffMessage::where('sender_id', $userId)
            ->where('recipient_id', Auth::id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'other' => [
                'id'    => $other->id,
                'name'  => $other->name,
                'email' => $other->email,
                'role'  => $other->roles->first()?->name,
            ],
            'messages' => $messages,
        ]);
    }

    // ── Send a staff message ─────────────────────────────────────
    public function send(Request $request)
    {
        $validated = $request->validate([
            'recipient_id' => 'required|integer|exists:users,id',
            'message_text' => 'required|string|min:1|max:2000',
        ]);

        $other = User::find($validated['recipient_id']);
        if (!$other || !$this->isAllowedContact($other)) {
            return response()->json(['message' => 'You are not allowed to message this user.'], 403);
        }

        $message = StaffMessage::create([
            'sender_id'    => Auth::id(),
            'recipient_id' => $validated['recipient_id'],
            'message_text' => $validated['message_text'],
            'is_read'      => false,
        ]);

        Notification::create([
            'user_id'      => $other->id,
            'type'         => 'staff_message',
            'reference_id' => $message->staff_message_id,
            'message'      => "New message from {$this->user->name}.",
            'is_read'      => false,
        ]);

        AuditLogger::log('staff_messages', $message->staff_message_id, 'created', null, $message->toArray());

        return response()->json([
            'message'      => 'Message sent.',
            'sent_message' => $message->load(['sender:id,name,email', 'recipient:id,name,email']),
        ], 201);
    }

    // ── Unread count ──────────────────────────────────────────────
    public function unreadCount()
    {
        return response()->json([
            'unread_count' => StaffMessage::where('recipient_id', Auth::id())->where('is_read', false)->count(),
        ]);
    }
}
