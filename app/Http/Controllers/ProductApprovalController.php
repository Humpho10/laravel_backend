<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Notification;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductApprovalController extends Controller
{
    /**
     * 1. APPROVE METHOD
     * Handles initial approvals and re-approvals after a seller rectifies an issue.
     */
    public function approve(Request $request, int $id)
    {
        // RECTIFIED: Explicitly search using your custom 'product_id' primary key column instead of findOrFail
        $product = Product::where('product_id', $id)->firstOrFail();
        $user = Auth::user();

        // Matches both initial paid items and items fixed by the seller
        $allowedStates = ['paid_pending_review', 'rectified_awaiting_review'];
        if (!in_array($product->status, $allowedStates)) {
            return response()->json([
                'error' => 'This product listing cannot be approved at this stage.'
            ], 400);
        }

        // Role & Scoped Category Authorization check
        if (!$user->hasRole('Admin')) {
            if ($user->hasRole('Product Manager') && $product->category_id !== $user->managed_category_id) {
                return response()->json([
                    'error' => 'Unauthorized. You do not manage this product\'s category department.'
                ], 403);
            }
        }

        $oldValue = $product->toArray();
        $product->update(['status' => 'approved']);

        AuditLogger::log('products', $product->product_id, 'approved_by_management', $oldValue, $product->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Product has been verified and published to the live feed.',
            'product' => $product
        ], 200);
    }

    /**
     * 2. REJECT METHOD
     * Rejects the post and drops a notification so the seller knows they need to rectify it.
     */
    public function reject(Request $request, int $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string|min:10|max:1000'
        ]);

        // RECTIFIED: Explicitly search using your custom 'product_id' primary key column
        $product = Product::where('product_id', $id)->firstOrFail();
        $user = Auth::user();

        // Allows you to reject a fresh paid item OR re-reject an item that wasn't fixed properly
        $allowedStates = ['paid_pending_review', 'rectified_awaiting_review'];
        if (!in_array($product->status, $allowedStates)) {
            return response()->json([
                'error' => 'This product listing cannot be rejected at this stage.'
            ], 400);
        }

        // Authorization checks
        if (!$user->hasRole('Admin')) {
            if ($user->hasRole('Product Manager') && $product->category_id !== $user->managed_category_id) {
                return response()->json([
                    'error' => 'Unauthorized. You do not manage this product\'s category department.'
                ], 403);
            }
        }

        $oldValue = $product->toArray();
        $product->update(['status' => 'rejected']);

        // SYSTEM NOTIFICATION: Tells the seller exactly what to rectify
        Notification::create([
            'user_id'      => $product->seller_id,
            'reference_id' => $product->product_id,
            'is_read'      => false,
            'message'      => "Your listing \"{$product->title}\" was rejected. Reason: " . $request->rejection_reason,
        ]);

        AuditLogger::log('products', $product->product_id, 'rejected_by_management', $oldValue, [
            'status' => 'rejected',
            'reason' => $request->rejection_reason
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Listing rejected. Rejection reasons and notifications have been delivered to the seller.',
            'status'  => 'rejected'
        ], 200);
    }
}
