<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * @var \App\Models\User|null
     */
    protected $user;

    // Super Admin only handles system maintenance/performance and staff —
    // they never see per-listing or buyer/seller-message activity, even if
    // a notification of this type slipped through from elsewhere. Kept as
    // a blacklist (not a whitelist) so newly-added, non-listing notification
    // types still reach them by default.
    private const SUPER_ADMIN_HIDDEN_TYPES = [
        'new_listing',
        'listing_resubmitted',
        'product_approved',
        'product_rejected',
        'new_message',
        'new_message_oversight',
    ];

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

    /**
     * Base notifications query for the current user, with the listing/
     * message noise filtered out when they're a Super Admin.
     */
    private function baseQuery()
    {
        $query = Notification::where('user_id', Auth::id());

        if ($this->user->hasRole('Super-Admin')) {
            $query->whereNotIn('type', self::SUPER_ADMIN_HIDDEN_TYPES);
        }

        return $query;
    }

    // ── All notifications for logged in user ──────────────────
    public function index()
    {
        if (!$this->user->can('notification-view')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view notifications.'], 403);
        }

        $notifications = $this->baseQuery()
            ->latest()
            ->get();

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => $notifications->where('is_read', false)->count(),
        ], 200);
    }

    // ── Mark one notification as read ─────────────────────────
    public function markRead(int $id)
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

    // ── Mark all notifications as read ────────────────────────
    public function markAllRead()
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

    // ── Delete a notification ─────────────────────────────────
    public function destroy(int $id)
    {
        if (!$this->user->can('notification-delete')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to delete notifications.'], 403);
        }

        $notification = Notification::where('notification_id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted.'
        ], 200);
    }

    // ── Unread count only ─────────────────────────────────────
    public function unreadCount()
    {
        if (!$this->user->can('notification-view')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view notifications.'], 403);
        }

        $count = $this->baseQuery()
            ->where('is_read', false)
            ->count();

        return response()->json([
            'unread_count' => $count
        ], 200);
    }
}