<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Notification;
use App\Models\Message;
use App\Models\ProductManagerAssignment;
use App\Helpers\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use App\Mail\ProductApprovedMail;
use App\Mail\ProductRejectedMail;

class ProductManagerController extends Controller
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

    // ── HELPER — Get assigned category IDs for this PM ────────
    private function assignedCategoryIds()
    {
        return ProductManagerAssignment::where('product_manager_id', Auth::id())
            ->pluck('category_id')
            ->toArray();
    }

    // ── STATS ─────────────────────────────────────────────────
    public function stats(Request $request)
    {
        // Check if user has permission to view dashboard
        if (!$this->user->can('dashboard-view') && !$this->user->can('product-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view dashboard stats.'], 403);
        }

        $categoryIds = $this->assignedCategoryIds();

        // Base query scoped to the categories this PM is assigned to.
        $scoped = fn () => Product::whereIn('category_id', $categoryIds);

        $total    = (clone $scoped())->count();
        $pending  = (clone $scoped())->where('status', 'pending')->count();
        $approved = (clone $scoped())->where('status', 'approved')->count();
        $rejected = (clone $scoped())->where('status', 'rejected')->count();

        // Listings this PM personally reviewed in the last 7 days.
        $reviewedThisWeek = (clone $scoped())
            ->where('reviewed_by', Auth::id())
            ->where('reviewed_at', '>=', now()->subDays(7))
            ->count();

        // ── Per-category breakdown (stacked bar chart) ──────────
        $names = \App\Models\Category::whereIn('category_id', $categoryIds)
            ->pluck('name', 'category_id');

        $grouped = (clone $scoped())
            ->selectRaw('category_id, status, COUNT(*) as c')
            ->groupBy('category_id', 'status')
            ->get();

        $byCategory = [];
        foreach ($categoryIds as $cid) {
            $byCategory[$cid] = [
                'name'     => $names[$cid] ?? 'Category #' . $cid,
                'pending'  => 0,
                'approved' => 0,
                'rejected' => 0,
                'total'    => 0,
            ];
        }
        foreach ($grouped as $row) {
            if (!isset($byCategory[$row->category_id])) {
                continue;
            }
            $status = in_array($row->status, ['pending', 'approved', 'rejected']) ? $row->status : 'pending';
            $byCategory[$row->category_id][$status] += $row->c;
            $byCategory[$row->category_id]['total']  += $row->c;
        }
        // Sort busiest first, keep the top 8 for a readable chart.
        $byCategory = collect($byCategory)->sortByDesc('total')->take(8)->values();

        // ── New listings per day, last 14 days (trend area) ─────
        $since  = now()->subDays(13)->startOfDay();
        $counts = (clone $scoped())
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $dailyNew = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $key = $day->format('Y-m-d');
            $dailyNew[] = [
                'date'  => $day->format('M j'),
                'count' => (int) ($counts[$key] ?? 0),
            ];
        }

        // ── Oldest pending listings — the PM's review queue ─────
        $recentPending = (clone $scoped())
            ->with(['seller:id,name', 'category:category_id,name', 'images'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->take(6)
            ->get()
            ->map(fn ($p) => [
                'product_id' => $p->product_id,
                'title'      => $p->title,
                'price'      => $p->price,
                'condition'  => $p->condition,
                'category'   => $p->category?->name,
                'seller'     => $p->seller?->name,
                'image'      => $p->images->first()->image_path ?? null,
                'created_at' => $p->created_at,
            ]);

        // ── Period performance (week / month / year / custom) ───
        $period = $this->periodPerformance($request, $categoryIds);

        return response()->json([
            'stats' => [
                'assigned_categories' => count($categoryIds),
                'total_products'      => $total,
                'pending_products'    => $pending,
                'approved_products'   => $approved,
                'rejected_products'   => $rejected,
                'reviewed_this_week'  => $reviewedThisWeek,
            ],
            'by_category'    => $byCategory,
            'daily_new'      => $dailyNew,
            'recent_pending' => $recentPending,
            'period'         => $period,
        ], 200);
    }

    // ── PERIOD PERFORMANCE — accountability over a date range ──
    // Accepts ?from=YYYY-MM-DD&to=YYYY-MM-DD (defaults to the current month).
    // Returns activity counts, the value of listings approved in the window,
    // any recorded sales revenue, and a time-bucketed series for charting.
    private function periodPerformance(Request $request, array $categoryIds)
    {
        $to = $request->filled('to')
            ? \Illuminate\Support\Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        $from = $request->filled('from')
            ? \Illuminate\Support\Carbon::parse($request->input('from'))->startOfDay()
            : now()->startOfMonth();

        // Guard against a reversed range.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $scoped = fn () => Product::whereIn('category_id', $categoryIds);

        // Activity in the window.
        $submitted = (clone $scoped())->whereBetween('created_at', [$from, $to])->count();
        $approved  = (clone $scoped())->where('status', 'approved')->whereBetween('reviewed_at', [$from, $to])->count();
        $rejected  = (clone $scoped())->where('status', 'rejected')->whereBetween('reviewed_at', [$from, $to])->count();
        $reviewed  = (clone $scoped())->where('reviewed_by', Auth::id())->whereBetween('reviewed_at', [$from, $to])->count();

        // Choose a readable granularity based on the span.
        $days = $from->diffInDays($to) + 1;
        $unit = $days <= 45 ? 'day' : ($days <= 731 ? 'month' : 'year');
        $sqlFmt = ['day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y'][$unit];
        $keyFmt = ['day' => 'Y-m-d', 'month' => 'Y-m', 'year' => 'Y'][$unit];
        $labelFmt = ['day' => 'M j', 'month' => 'M Y', 'year' => 'Y'][$unit];

        $submittedByBucket = (clone $scoped())
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("DATE_FORMAT(created_at, '{$sqlFmt}') as k, COUNT(*) as c")
            ->groupBy('k')->pluck('c', 'k');

        $approvedByBucket = (clone $scoped())
            ->where('status', 'approved')
            ->whereBetween('reviewed_at', [$from, $to])
            ->selectRaw("DATE_FORMAT(reviewed_at, '{$sqlFmt}') as k, COUNT(*) as c")
            ->groupBy('k')->pluck('c', 'k');

        $series = [];
        $cursor = $from->copy();
        $guard = 0;
        while ($cursor->lessThanOrEqualTo($to) && $guard++ < 500) {
            $key = $cursor->format($keyFmt);
            $series[] = [
                'label'     => $cursor->format($labelFmt),
                'submitted' => (int) ($submittedByBucket[$key] ?? 0),
                'approved'  => (int) ($approvedByBucket[$key] ?? 0),
            ];
            match ($unit) {
                'day'   => $cursor->addDay(),
                'month' => $cursor->addMonthNoOverflow(),
                'year'  => $cursor->addYear(),
            };
        }

        return [
            'from'      => $from->toDateString(),
            'to'        => $to->toDateString(),
            'unit'      => $unit,
            'submitted' => $submitted,
            'approved'  => $approved,
            'rejected'  => $rejected,
            'reviewed'  => $reviewed,
            'series'    => $series,
        ];
    }

    // ── LIST PRODUCTS IN ASSIGNED CATEGORIES ──────────────────
    // Supports ?status=, ?search=, ?page=, ?per_page= (server-side pagination).
    public function listProducts(Request $request)
    {
        if (!$this->user->can('product-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view products.'], 403);
        }

        $categoryIds = $this->assignedCategoryIds();

        $emptyMeta = ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 0];
        $emptyCounts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];

        if (empty($categoryIds)) {
            return response()->json([
                'message'  => 'You have no categories assigned yet.',
                'products' => [],
                'meta'     => $emptyMeta,
                'counts'   => $emptyCounts,
            ], 200);
        }

        $base = fn () => Product::whereIn('category_id', $categoryIds);

        // Status counts are computed independent of the current status/search
        // filters so the tab badges always reflect the full assigned set.
        $counts = [
            'all'      => (clone $base())->count(),
            'pending'  => (clone $base())->where('status', 'pending')->count(),
            'approved' => (clone $base())->where('status', 'approved')->count(),
            'rejected' => (clone $base())->where('status', 'rejected')->count(),
        ];

        $query = Product::with([
            'seller:id,name,email,phone',
            'category:category_id,name',
            'images',
        ])->whereIn('category_id', $categoryIds);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('seller', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage  = min((int) $request->input('per_page', 10), 50) ?: 10;
        $products = $query->latest()->paginate($perPage)->withQueryString();

        return response()->json([
            'products' => $products->items(),
            'meta'     => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
            'counts' => $counts,
        ], 200);
    }

    // ── VIEW SINGLE PRODUCT ───────────────────────────────────
    public function viewProduct($id)
    {
        if (!$this->user->can('product-view')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view product details.'], 403);
        }

        $categoryIds = $this->assignedCategoryIds();

        $product = Product::with([
            'seller:id,name,email,phone',
            'category:category_id,name',
            'images',
        ])->findOrFail($id);

        // Business rule: PM can only view products in their assigned categories
        if (!in_array($product->category_id, $categoryIds)) {
            return response()->json([
                'error' => 'You are not assigned to this product\'s category.'
            ], 403);
        }

        return response()->json([
            'product' => $product
        ], 200);
    }

    // ── APPROVE PRODUCT ───────────────────────────────────────
    public function approveProduct(Request $request, $id)
    {
        if (!$this->user->can('product-approve')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to approve products.'], 403);
        }

        $categoryIds = $this->assignedCategoryIds();

        $product = Product::findOrFail($id);

        // Business rule: PM can only approve products in their assigned categories
        if (!in_array($product->category_id, $categoryIds)) {
            return response()->json([
                'error' => 'You are not assigned to this product\'s category.'
            ], 403);
        }

        if ($product->status === 'approved') {
            return response()->json([
                'error' => 'This product is already approved.'
            ], 400);
        }

        $oldValue = $product->toArray();

        $product->status           = 'approved';
        $product->reviewed_by      = Auth::id();
        $product->reviewed_at      = now();
        $product->rejection_reason = null;
        $product->save();

        AuditLogger::log('products', $product->product_id, 'updated', $oldValue, $product->toArray());

        // Notify seller
        Notification::create([
            'user_id'      => $product->seller_id,
            'type'         => 'product_approved',
            'reference_id' => $product->product_id,
            'message'      => "Your product listing \"{$product->title}\" has been approved and is now live.",
            'is_read'      => false,
        ]);

        if ($product->seller?->email) {
            Mail::to($product->seller->email)->send(new ProductApprovedMail($product));
        }

        return response()->json([
            'message' => 'Product approved successfully.',
            'product' => $product,
        ], 200);
    }

    // ── REJECT PRODUCT ────────────────────────────────────────
    public function rejectProduct(Request $request, $id)
    {
        if (!$this->user->can('product-reject')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to reject products.'], 403);
        }

        $categoryIds = $this->assignedCategoryIds();

        $product = Product::findOrFail($id);

        // Business rule: PM can only reject products in their assigned categories
        if (!in_array($product->category_id, $categoryIds)) {
            return response()->json([
                'error' => 'You are not assigned to this product\'s category.'
            ], 403);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|min:10',
        ]);

        if ($product->status === 'rejected') {
            return response()->json([
                'error' => 'This product is already rejected.'
            ], 400);
        }

        $oldValue = $product->toArray();

        $product->status           = 'rejected';
        $product->reviewed_by      = Auth::id();
        $product->reviewed_at      = now();
        $product->rejection_reason = $validated['rejection_reason'];
        $product->save();

        AuditLogger::log('products', $product->product_id, 'updated', $oldValue, $product->toArray());

        // Notify seller
        Notification::create([
            'user_id'      => $product->seller_id,
            'type'         => 'product_rejected',
            'reference_id' => $product->product_id,
            'message'      => "Your product listing \"{$product->title}\" has been rejected.",
            'is_read'      => false,
        ]);

        // Send rejection message to seller
        Message::create([
            'product_id'   => $product->product_id,
            'sender_id'    => Auth::id(),
            'recipient_id' => $product->seller_id,
            'message_text' => "Your listing \"{$product->title}\" was rejected for the following reason: {$validated['rejection_reason']}",
            'is_read'      => false,
        ]);

        if ($product->seller?->email) {
            Mail::to($product->seller->email)->send(new ProductRejectedMail($product));
        }

        return response()->json([
            'message' => 'Product rejected and seller has been notified.',
            'product' => $product,
        ], 200);
    }

    // ── VIEW MESSAGES ─────────────────────────────────────────
    public function listMessages()
    {
        if (!$this->user->can('message-view')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view messages.'], 403);
        }

        $messages = Message::with([
            'sender:id,name,email',
            'recipient:id,name,email',
        ])
        ->where(function($q) {
            $q->where('sender_id',    Auth::id())
              ->orWhere('recipient_id', Auth::id());
        })
        ->latest()
        ->get();

        return response()->json([
            'messages' => $messages,
            'count'    => $messages->count(),
        ], 200);
    }

    // ── VIEW NOTIFICATIONS ────────────────────────────────────
    public function listNotifications()
    {
        if (!$this->user->can('notification-view')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view notifications.'], 403);
        }

        $notifications = Notification::where('user_id', Auth::id())
            ->latest()
            ->get();

        return response()->json([
            'notifications'  => $notifications,
            'unread_count'   => $notifications->where('is_read', false)->count(),
        ], 200);
    }

    // ── MARK NOTIFICATION AS READ ─────────────────────────────
    public function markNotificationRead($id)
    {
        if (!$this->user->can('notification-mark-read')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to mark notifications as read.'], 403);
        }

        $notification = Notification::where('notification_id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $notification->is_read = true;
        $notification->save();

        return response()->json([
            'message' => 'Notification marked as read.'
        ], 200);
    }

    // ── MARK ALL NOTIFICATIONS AS READ ───────────────────────
    public function markAllNotificationsRead()
    {
        if (!$this->user->can('notification-mark-read')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to mark notifications as read.'], 403);
        }

        Notification::where('user_id', Auth::id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'message' => 'All notifications marked as read.'
        ], 200);
    }

    // ── GET PROFILE ───────────────────────────────────────────
    public function getProfile(Request $request)
    {
        // No permission check – users always see their own profile
        return response()->json([
            'user' => $request->user(),
        ], 200);
    }

    // ── UPDATE PROFILE ────────────────────────────────────────
    public function updateProfile(Request $request)
    {
        // No permission check – users always update their own profile
        $user = $request->user();

        $validated = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'phone'    => 'sometimes|string|max:20',
            'location' => 'sometimes|string|max:100',
            'password' => 'sometimes|string|min:8|confirmed',
            'avatar'   => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if (isset($validated['name']))     $user->name     = $validated['name'];
        if (isset($validated['phone']))    $user->phone    = $validated['phone'];
        if (isset($validated['location'])) $user->location = $validated['location'];

        if (isset($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();

        AuditLogger::log('users', $user->id, 'updated', [], $user->toArray());

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $user,
        ], 200);
    }
}