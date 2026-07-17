<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use App\Models\Notification;
use App\Http\Controllers\Controller;
use App\Models\ProductManagerAssignment;
use App\Models\SubCategory; // 👈 Added for clarity
use App\Helpers\AuditLogger;
use App\Helpers\EmailVerifier;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Helpers\Mailer;
use Illuminate\Support\Facades\Auth;
use App\Mail\ProductApprovedMail;
use App\Mail\ProductRejectedMail;
use App\Mail\ProductManagerCreatedMail;
use App\Mail\UserActivatedMail;
use App\Mail\UserDeactivatedMail;

class ManagerController extends Controller
{
    // ── Helper: Get the authenticated user with correct type hint ──
    protected function user()
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        return $user;
    }

    // ── LIST USERS ────────────────────────────────────────────
    public function listUsers(Request $request)
    {
        if (!$this->user()->can('user-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view users.'], 403);
        }

        $query = User::role('User');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Base query (before pagination) is reused to compute active/inactive
        // counts across the *whole* filtered set, not just the current page.
        $activeCount   = (clone $query)->where('is_active', true)->count();
        $inactiveCount = (clone $query)->where('is_active', false)->count();

        $perPage   = min(max((int) $request->get('per_page', 10), 5), 100);
        $paginated = $query->orderBy('created_at', 'desc')
                    ->paginate($perPage, ['id', 'name', 'email', 'phone', 'location', 'is_active', 'created_at'])
                    ->withQueryString();

        return response()->json([
            'users' => $paginated->items(),
            'count' => $paginated->total(),
            'meta'  => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
            'counts' => [
                'active'   => $activeCount,
                'inactive' => $inactiveCount,
            ],
        ], 200);
    }

    // ── DEACTIVATE USER ───────────────────────────────────────
    public function deactivateUser(Request $request, $id)
    {
        if (!$this->user()->can('user-deactivate')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to deactivate users.'], 403);
        }

        $user = User::findOrFail($id);

        // Business rule – not a permission check
        if ($user->hasRole('Admin') || $user->hasRole('Super-Admin')) {
            return response()->json([
                'error' => 'Admin and Super Admin accounts cannot be deactivated here.'
            ], 403);
        }

        $oldValue = $user->toArray();

        $user->is_active = false;
        $user->save();
        $user->tokens()->delete();

        AuditLogger::log('users', $user->id, 'updated', $oldValue, $user->toArray());

        Mailer::send($user->email, new UserDeactivatedMail($user));

        return response()->json([
            'message' => "{$user->name} has been deactivated.",
        ], 200);
    }

    // ── ACTIVATE USER ─────────────────────────────────────────
    public function activateUser(Request $request, $id)
    {
        if (!$this->user()->can('user-activate')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to activate users.'], 403);
        }

        $user = User::findOrFail($id);

        $oldValue = $user->toArray();

        $user->is_active = true;
        $user->save();

        AuditLogger::log('users', $user->id, 'updated', $oldValue, $user->toArray());

        Mailer::send($user->email, new UserActivatedMail($user));

        return response()->json([
            'message' => "{$user->name} has been reactivated.",
        ], 200);
    }

    // ── CREATE PRODUCT MANAGER ────────────────────────────────
    public function createProductManager(Request $request)
    {
        if (!$this->user()->can('pm-create')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to create product managers.'], 403);
        }

        $validated = $request->validate([
            'name'           => 'required|string|max:100',
            'email'          => 'required|email|unique:users,email',
            'password'       => 'required|string|min:8',
            'phone'          => 'nullable|string|max:20',
            'location'       => 'nullable|string|max:100',
            'category_id'    => 'required|array|min:1',
            'category_id.*'  => 'exists:categories,category_id',
        ]);

        $pm = User::create([
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'password'          => Hash::make($validated['password']),
            'phone'             => $validated['phone'] ?? null,
            'location'          => $validated['location'] ?? null,
            'is_active'         => true,
        ]);

        $pm->assignRole('Product-Manager');

        foreach ($validated['category_id'] as $categoryId) {
            ProductManagerAssignment::create([
                'product_manager_id' => $pm->id,
                'category_id'        => $categoryId,
            ]);
        }

        AuditLogger::log('users', $pm->id, 'created', null, $pm->toArray());

        // Send email notification (non-fatal — creating the PM must succeed
        // even if the welcome email can't be delivered).
        Mailer::send($pm->email, new ProductManagerCreatedMail($pm->name, $pm->email));

        // Must verify their email before they can log in — send the same
        // link-based verification flow used everywhere else in the app.
        EmailVerifier::send($pm);

        // Send in-app notification
        Notification::create([
            'user_id'      => $pm->id,
            'type'         => 'account_created',
            'reference_id' => $pm->id,
            'message'      => 'Your Product Manager account has been created. Please check your email to verify your account before logging in.',
            'is_read'      => false,
        ]);

        $assignments = ProductManagerAssignment::where('product_manager_id', $pm->id)
            ->with('category:category_id,name')
            ->get();

        return response()->json([
            'message'         => 'Product Manager created and assigned successfully.',
            'product_manager' => $pm,
            'assignments'     => $assignments,
        ], 201);
    }

    // ── ASSIGN MORE CATEGORIES ────────────────────────────────
    public function assignCategory(Request $request)
    {
        if (!$this->user()->can('pm-assign-category')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to assign categories.'], 403);
        }

        $validated = $request->validate([
            'product_manager_id' => 'required|exists:users,id',
            'category_id'        => 'required|exists:categories,category_id',
        ]);

        $pm = User::findOrFail($validated['product_manager_id']);

        if (!$pm->hasRole('Product-Manager')) {
            return response()->json([
                'error' => 'You can only assign categories to Product Manager accounts.'
            ], 400);
        }

        $exists = ProductManagerAssignment::where('product_manager_id', $validated['product_manager_id'])
                    ->where('category_id', $validated['category_id'])
                    ->exists();

        if ($exists) {
            return response()->json([
                'error' => 'This product manager is already assigned to this category.'
            ], 400);
        }

        $assignment = ProductManagerAssignment::create($validated);

        AuditLogger::log('product_manager_assignments', $assignment->id, 'created', null, $assignment->toArray());

        return response()->json([
            'message'    => 'Category assigned successfully.',
            'assignment' => $assignment->load([
                'productManager:id,name,email',
                'category:category_id,name',
            ]),
        ], 201);
    }

    // ── REMOVE ASSIGNMENT ─────────────────────────────────────
    public function removeAssignment($id)
    {
        if (!$this->user()->can('pm-remove-category')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to remove category assignments.'], 403);
        }

        $assignment = ProductManagerAssignment::findOrFail($id);

        $oldValue = $assignment->toArray();
        $assignment->delete();

        AuditLogger::log('product_manager_assignments', $id, 'deleted', $oldValue, null);

        return response()->json([
            'message' => 'Assignment removed successfully.'
        ], 200);
    }

    // ── LIST PRODUCT MANAGERS ─────────────────────────────────
    public function listProductManagers()
    {
        if (!$this->user()->can('pm-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view product managers.'], 403);
        }

        $pms = User::role('Product-Manager')
                    ->orderBy('created_at', 'desc')
                    ->get(['id', 'name', 'email', 'phone', 'location', 'is_active', 'created_at']);

        foreach ($pms as $pm) {
            $pm->assignments = ProductManagerAssignment::where('product_manager_id', $pm->id)
                ->with('category:category_id,name')
                ->get();
        }

        return response()->json([
            'product_managers' => $pms
        ], 200);
    }

    // ── UPDATE PRODUCT MANAGER ────────────────────────────────
    public function updateProductManager(Request $request, $id)
    {
        if (!$this->user()->can('pm-edit')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to edit product managers.'], 403);
        }

        $pm = User::findOrFail($id);

        if (!$pm->hasRole('Product-Manager')) {
            return response()->json(['error' => 'This account is not a Product Manager.'], 400);
        }

        $validated = $request->validate([
            'name'           => 'required|string|max:100',
            'email'          => 'required|email|unique:users,email,' . $pm->id,
            'phone'          => 'nullable|string|max:20',
            'location'       => 'nullable|string|max:100',
            'password'       => 'nullable|string|min:8',
            'category_id'    => 'required|array|min:1',
            'category_id.*'  => 'exists:categories,category_id',
        ]);

        $oldValue = $pm->toArray();

        $pm->name     = $validated['name'];
        $pm->email    = $validated['email'];
        $pm->phone    = $validated['phone'] ?? null;
        $pm->location = $validated['location'] ?? null;

        if (!empty($validated['password'])) {
            $pm->password = Hash::make($validated['password']);
        }

        $pm->save();

        // Replace category assignments with the submitted set.
        ProductManagerAssignment::where('product_manager_id', $pm->id)->delete();
        foreach ($validated['category_id'] as $categoryId) {
            ProductManagerAssignment::create([
                'product_manager_id' => $pm->id,
                'category_id'        => $categoryId,
            ]);
        }

        AuditLogger::log('users', $pm->id, 'updated', $oldValue, $pm->toArray());

        $assignments = ProductManagerAssignment::where('product_manager_id', $pm->id)
            ->with('category:category_id,name')
            ->get();

        return response()->json([
            'message'         => 'Product Manager updated successfully.',
            'product_manager' => $pm,
            'assignments'     => $assignments,
        ], 200);
    }

    // ── DELETE PRODUCT MANAGER ────────────────────────────────
    public function deleteProductManager($id)
    {
        if (!$this->user()->can('pm-delete')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to delete product managers.'], 403);
        }

        $pm = User::findOrFail($id);

        if (!$pm->hasRole('Product-Manager')) {
            return response()->json(['error' => 'This account is not a Product Manager.'], 400);
        }

        if ($pm->id === Auth::id()) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        $oldValue = $pm->toArray();
        $pmName   = $pm->name;

        $pm->tokens()->delete();
        $pm->delete(); // cascades: product_manager_assignments rows; nulls out reviewed_by on any products they reviewed

        AuditLogger::log('users', $id, 'deleted', $oldValue, null);

        return response()->json([
            'message' => "{$pmName} has been removed.",
        ], 200);
    }

    // ── CATEGORY MANAGEMENT ───────────────────────────────────
    public function listCategories()
    {
        if (!$this->user()->can('category-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view categories.'], 403);
        }

        $categories = Category::with('subcategories')
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return response()->json([
            'categories' => $categories
        ], 200);
    }

    public function createCategory(Request $request)
    {
        if (!$this->user()->can('category-create')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to create categories.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:categories,name',
        ]);

        $category = Category::create(['name' => $validated['name']]);

        AuditLogger::log('categories', $category->category_id, 'created', null, $category->toArray());

        return response()->json([
            'message'  => 'Category created successfully.',
            'category' => $category,
        ], 201);
    }

    public function updateCategory(Request $request, $id)
    {
        if (!$this->user()->can('category-edit')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to update categories.'], 403);
        }

        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:categories,name,' . $id . ',category_id',
        ]);

        $oldValue = $category->toArray();
        $category->update(['name' => $validated['name']]);

        AuditLogger::log('categories', $category->category_id, 'updated', $oldValue, $category->toArray());

        return response()->json([
            'message'  => 'Category updated successfully.',
            'category' => $category,
        ], 200);
    }

    public function deleteCategory($id)
    {
        if (!$this->user()->can('category-delete')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to delete categories.'], 403);
        }

        $category = Category::findOrFail($id);

        if ($category->products()->count() > 0) {
            return response()->json([
                'error' => 'Cannot delete a category that has products under it.'
            ], 400);
        }

        $oldValue = $category->toArray();
        $category->delete();

        AuditLogger::log('categories', $id, 'deleted', $oldValue, null);

        return response()->json([
            'message' => 'Category deleted successfully.'
        ], 200);
    }

    // ── SUBCATEGORY MANAGEMENT ────────────────────────────────
    public function addSubcategory(Request $request, $categoryId)
    {
        if (!$this->user()->can('category-create')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to create subcategories.'], 403);
        }

        $category = Category::findOrFail($categoryId);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $sub = SubCategory::create([
            'category_id'       => $category->category_id,
            'sub_category_name' => $validated['name'],
        ]);

        AuditLogger::log('sub_categories', $sub->subcategory_id, 'created', null, $sub->toArray());

        return response()->json([
            'message'     => 'Subcategory added successfully.',
            'subcategory' => $sub,
        ], 201);
    }

    public function removeSubcategory($id)
    {
        if (!$this->user()->can('category-delete')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to remove subcategories.'], 403);
        }

        $sub = SubCategory::findOrFail($id);
        $old = $sub->toArray();
        $sub->delete();

        AuditLogger::log('sub_categories', $id, 'deleted', $old, null);

        return response()->json([
            'message' => 'Subcategory removed successfully.'
        ], 200);
    }

    // ── LISTING APPROVAL ──────────────────────────────────────
    public function listProducts(Request $request)
    {
        if (!$this->user()->can('product-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view products.'], 403);
        }

        $query = Product::with([
            'seller:id,name,email,phone,location',
            'category:category_id,name',
            'images',
        ]);

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('seller', function ($sq) use ($search) {
                      $sq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $perPage   = min(max((int) $request->get('per_page', 10), 5), 100);
        $paginated = $query->latest()->paginate($perPage)->withQueryString();

        return response()->json([
            'products' => $paginated->items(),
            'count'    => $paginated->total(),
            'meta'     => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ], 200);
    }

    public function viewProduct($id)
    {
        if (!$this->user()->can('product-view')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view product details.'], 403);
        }

        $product = Product::with([
            'seller:id,name,email,phone,location',
            'category:category_id,name',
            'images',
        ])->findOrFail($id);

        return response()->json([
            'product' => $product
        ], 200);
    }

    public function approveProduct(Request $request, $id)
    {
        if (!$this->user()->can('product-approve')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to approve products.'], 403);
        }

        $product = Product::findOrFail($id);

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

        Notification::create([
            'user_id'      => $product->seller_id,
            'type'         => 'product_approved',
            'reference_id' => $product->product_id,
            'message'      => "Your product listing \"{$product->title}\" has been approved and is now live.",
            'is_read'      => false,
        ]);

        Mailer::send($product->seller?->email, new ProductApprovedMail($product));

        return response()->json([
            'message' => 'Product approved successfully.',
            'product' => $product,
        ], 200);
    }

    public function rejectProduct(Request $request, $id)
    {
        if (!$this->user()->can('product-reject')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to reject products.'], 403);
        }

        $product = Product::findOrFail($id);

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
            'message'      => "Your product listing \"{$product->title}\" has been rejected. Please check your messages for the reason.",
            'is_read'      => false,
        ]);

        // Send rejection message to seller
        \App\Models\Message::create([
            'product_id'   => $product->product_id,
            'sender_id'    => Auth::id(),
            'recipient_id' => $product->seller_id,
            'message_text' => "Your listing \"{$product->title}\" was rejected for the following reason: {$validated['rejection_reason']}",
            'is_read'      => false,
        ]);

        Mailer::send($product->seller?->email, new ProductRejectedMail($product));

        return response()->json([
            'message' => 'Product rejected and seller has been notified.',
            'product' => $product,
        ], 200);
    }

    // ── STATS ─────────────────────────────────────────────────
    public function stats()
    {
        // Allow if user has any of these dashboard permissions
        if (!$this->user()->can('dashboard-view') &&
            !$this->user()->can('product-list') &&
            !$this->user()->can('user-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view dashboard stats.'], 403);
        }

        return response()->json([
            'stats' => [
                'total_users'            => User::role('User')->count(),
                'total_product_managers' => User::role('Product-Manager')->count(),
                'total_products'         => Product::count(),
                'pending_products'       => Product::where('status', 'pending')->count(),
                'approved_products'      => Product::where('status', 'approved')->count(),
                'rejected_products'      => Product::where('status', 'rejected')->count(),
            ],
        ], 200);
    }

    // ── STATS: TRENDS (last 30 days) ──────────────────────────
    public function trends()
    {
        if (!$this->user()->can('dashboard-view') &&
            !$this->user()->can('product-list') &&
            !$this->user()->can('user-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view dashboard stats.'], 403);
        }

        $days  = 30;
        $start = now()->subDays($days - 1)->startOfDay();

        $listingCounts = Product::where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $userCounts = User::role('User')
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->format('Y-m-d');
            $series[] = [
                'date'     => $date,
                'listings' => (int) ($listingCounts[$date] ?? 0),
                'users'    => (int) ($userCounts[$date] ?? 0),
            ];
        }

        return response()->json(['trends' => $series], 200);
    }

    // ── GET PROFILE ───────────────────────────────────────────
    public function getProfile(Request $request)
    {
        // No permission check – users always see their own profile
        return response()->json(['user' => $request->user()], 200);
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
            if ($user->avatar && \Illuminate\Support\Facades\Storage::disk('public')->exists($user->avatar)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();
        AuditLogger::log('users', $user->id, 'updated', [], $user->toArray());

        return response()->json(['message' => 'Profile updated successfully.', 'user' => $user], 200);
    }
}