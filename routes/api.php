<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\ManagerController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductManagerController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\SellerRatingController;
use App\Http\Controllers\ImageSearchController;

// ── Auth ──────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
    Route::post('/google',   [AuthController::class, 'google']);              // ← Google Sign-In
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']); // ← new
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);  // ← new
    Route::post('/verify-email',    [AuthController::class, 'verifyEmail']);    // ← new
    Route::post('/resend-verification-public', [AuthController::class, 'resendVerificationPublic']);
});

Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);
    Route::post('/resend-verification', [AuthController::class, 'resendVerification']); // ← new
});

// ── Public Routes (No Auth Required) ─────────────────────────
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{categoryId}/subcategories', [CategoryController::class, 'subcategories']);
Route::get('/products', [ProductController::class, 'browse']);
Route::get('/products/{slugHash}', [ProductController::class, 'show'])
    ->name('product.show')
    ->where('slugHash', '.*-[A-Za-z0-9]+');

Route::get('/stats', [StatsController::class, 'index']); // ← new public stats
Route::get('/sellers/top-rated', [SellerRatingController::class, 'topRated']);
Route::get('/settings/public', [StatsController::class, 'publicSettings']);

// Contact page — rate-limited (5/min per IP) since it's unauthenticated.
Route::post('/contact', [ContactController::class, 'send'])->middleware('throttle:5,1');

// Photo search (homepage camera button) — rate-limited since it calls a
// third-party API (Hugging Face) per request.
Route::post('/search/image', [ImageSearchController::class, 'search'])->middleware('throttle:5,1');

// ── SUPER ADMIN ROUTES ───────────────────────────────────────
// These require permissions that only Super Admin has (or anyone with these specific permissions)
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Dashboard
    Route::get('/stats', [AdminController::class, 'stats']);

    // ── Users ──────────────────────────────────────────────
    Route::get('/users',               [AdminController::class, 'getUsers'])->middleware('can:user-list');
    Route::post('/users',              [AdminController::class, 'createUser'])->middleware('can:user-create');
    Route::put('/users/{id}',          [AdminController::class, 'updateUser'])->middleware('can:user-edit');
    Route::delete('/users/{id}',       [AdminController::class, 'deleteUser'])->middleware('can:user-delete');

    // ── Admins ─────────────────────────────────────────────
    Route::get('/admins',              [AdminController::class, 'getAdmins'])->middleware('can:admin-list');
    Route::post('/admins',             [AdminController::class, 'createAdmin'])->middleware('can:admin-create');
    Route::delete('/admins/{id}',      [AdminController::class, 'deleteAdmin'])->middleware('can:admin-delete');
    Route::get('/managers',            [AdminController::class, 'getAdmins'])->middleware('can:admin-list');
    Route::post('/managers',           [AdminController::class, 'createAdmin'])->middleware('can:admin-create');
    Route::delete('/managers/{id}',    [AdminController::class, 'deleteAdmin'])->middleware('can:admin-delete');

    // ── Product Managers (read-only oversight — Admin still owns
    //    creating/editing/removing PMs and their category assignments) ──
    Route::get('/product-managers',    [AdminController::class, 'getProductManagers'])->middleware('can:pm-list');

    // ── Roles ──────────────────────────────────────────────
    Route::get('/roles',               [AdminController::class, 'getRoles'])->middleware('can:role-list');
    Route::post('/roles',              [AdminController::class, 'createRole'])->middleware('can:role-create');
    Route::put('/roles/{id}',          [AdminController::class, 'updateRole'])->middleware('can:role-edit');
    Route::delete('/roles/{id}',       [AdminController::class, 'deleteRole'])->middleware('can:role-delete');

    // ── Permissions ────────────────────────────────────────
    Route::get('/permissions',         [AdminController::class, 'getPermissions'])->middleware('can:permission-list');
    Route::post('/permissions',        [AdminController::class, 'createPermission'])->middleware('can:permission-create');
    Route::delete('/permissions/{id}', [AdminController::class, 'deletePermission'])->middleware('can:permission-delete');

    // ── Audit ──────────────────────────────────────────────
    Route::get('/audit',               [AdminController::class, 'getAuditTrail'])->middleware('can:audit-list');
    Route::get('/audit/export',        [AdminController::class, 'exportAuditTrail'])->middleware('can:audit-list');

    // ── System Settings ──────────────────────────────────────
    Route::get('/settings',  [AdminController::class, 'getSettings']);
    Route::put('/settings',  [AdminController::class, 'updateSettings']);

    // ── Profile ────────────────────────────────────────────
    Route::get('/profile',  [AdminController::class, 'getProfile']);
    Route::post('/profile', [AdminController::class, 'updateProfile']);
});

// ── ADMIN (Operations Manager) ROUTES ────────────────────────
// These require permissions that an Admin/Manager typically has
Route::middleware(['auth:sanctum', 'manager'])->prefix('manager')->group(function () {
    // Dashboard
    Route::get('/stats', [ManagerController::class, 'stats']);
    Route::get('/stats/trends', [ManagerController::class, 'trends']); // ← new

    // ── Users ──────────────────────────────────────────────
    Route::get('/users',                       [ManagerController::class, 'listUsers'])->middleware('can:user-list');
    Route::patch('/users/{id}/deactivate',     [ManagerController::class, 'deactivateUser'])->middleware('can:user-deactivate');
    Route::patch('/users/{id}/activate',       [ManagerController::class, 'activateUser'])->middleware('can:user-activate');

    // ── Product Managers ──────────────────────────────────
    Route::get('/product-managers',            [ManagerController::class, 'listProductManagers'])->middleware('can:pm-list');
    Route::post('/product-managers',           [ManagerController::class, 'createProductManager'])->middleware('can:pm-create');
    Route::put('/product-managers/{id}',       [ManagerController::class, 'updateProductManager'])->middleware('can:pm-edit'); // ← new
    Route::delete('/product-managers/{id}',    [ManagerController::class, 'deleteProductManager'])->middleware('can:pm-delete'); // ← new

    // ── Category Assignments ──────────────────────────────
    Route::post('/assignments',                [ManagerController::class, 'assignCategory'])->middleware('can:pm-assign-category');
    Route::delete('/assignments/{id}',         [ManagerController::class, 'removeAssignment'])->middleware('can:pm-remove-category');

    // ── Categories ─────────────────────────────────────────
    Route::get('/categories',                  [ManagerController::class, 'listCategories'])->middleware('can:category-list');
    Route::post('/categories',                 [ManagerController::class, 'createCategory'])->middleware('can:category-create');
    Route::put('/categories/{id}',             [ManagerController::class, 'updateCategory'])->middleware('can:category-edit');
    Route::delete('/categories/{id}',          [ManagerController::class, 'deleteCategory'])->middleware('can:category-delete');
    Route::post('/categories/{id}/subcategories', [ManagerController::class, 'addSubcategory'])->middleware('can:category-create');
    Route::delete('/subcategories/{id}',       [ManagerController::class, 'removeSubcategory'])->middleware('can:category-delete');

    // ── Products ───────────────────────────────────────────
    Route::get('/products',                    [ManagerController::class, 'listProducts'])->middleware('can:product-list');
    Route::get('/products/{id}',               [ManagerController::class, 'viewProduct'])->middleware('can:product-view');
    Route::patch('/products/{id}/approve',     [ManagerController::class, 'approveProduct'])->middleware('can:product-approve');
    Route::patch('/products/{id}/reject',      [ManagerController::class, 'rejectProduct'])->middleware('can:product-reject');

    // ── Profile ────────────────────────────────────────────
    Route::get('/profile',  [ManagerController::class, 'getProfile']);
    Route::post('/profile', [ManagerController::class, 'updateProfile']);

    // ── Messages ───────────────────────────────────────────
    Route::get('/messages', [ManagerController::class, 'getMessages'])->middleware('can:message-view');

    // ── Contact Messages ───────────────────────────────────
    Route::get('/contact-messages',             [ContactMessageController::class, 'index'])->middleware('can:contact-view');
    Route::get('/contact-messages/{id}',        [ContactMessageController::class, 'show'])->middleware('can:contact-view');
    Route::post('/contact-messages/{id}/reply', [ContactMessageController::class, 'reply'])->middleware('can:contact-reply');
});

// ── PRODUCT MANAGER ROUTES ──────────────────────────────────
// These require permissions that a Product Manager typically has
Route::middleware(['auth:sanctum', 'pm'])->prefix('pm')->group(function () {
    // Dashboard
    Route::get('/stats', [ProductManagerController::class, 'stats']);

    // ── Products (scoped to their assigned categories) ────
    Route::get('/products',                       [ProductManagerController::class, 'listProducts'])->middleware('can:product-list');
    Route::get('/products/{id}',                  [ProductManagerController::class, 'viewProduct'])->middleware('can:product-view');
    Route::patch('/products/{id}/approve',        [ProductManagerController::class, 'approveProduct'])->middleware('can:product-approve');
    Route::patch('/products/{id}/reject',         [ProductManagerController::class, 'rejectProduct'])->middleware('can:product-reject');

    // ── Messages ───────────────────────────────────────────
    Route::get('/messages',                       [ProductManagerController::class, 'listMessages'])->middleware('can:message-view');

    // ── Notifications ─────────────────────────────────────
    Route::get('/notifications',                  [ProductManagerController::class, 'listNotifications'])->middleware('can:notification-view');
    Route::patch('/notifications/{id}/read',      [ProductManagerController::class, 'markNotificationRead'])->middleware('can:notification-mark-read');
    Route::patch('/notifications/read-all',       [ProductManagerController::class, 'markAllNotificationsRead'])->middleware('can:notification-mark-read');

    // ── Profile ────────────────────────────────────────────
    Route::get('/profile',  [ProductManagerController::class, 'getProfile']);
    Route::post('/profile', [ProductManagerController::class, 'updateProfile']);
});

// ── SELLER / BUYER ROUTES (Authenticated Users) ─────────────
Route::middleware('auth:sanctum')->group(function () {
    // ── Products ───────────────────────────────────────────
    Route::post('/products',                  [ProductController::class, 'store'])->middleware('can:product-create');
    Route::put('/products/{hashId}',          [ProductController::class, 'update'])->middleware('can:product-edit');
    Route::delete('/products/{hashId}',       [ProductController::class, 'destroy'])->middleware('can:product-delete');
    Route::get('/products/my/listings',       [ProductController::class, 'myListings'])->middleware('can:product-list');
    Route::post('/products/{hashId}/resubmit',[ProductController::class, 'resubmit'])->middleware('can:product-create');

    // ── Messaging ──────────────────────────────────────────
    Route::post('/products/{hashId}/messages',[ProductController::class, 'sendMessage'])->middleware('can:message-send');
    Route::get('/products/{hashId}/messages', [ProductController::class, 'getMessages'])->middleware('can:message-view');

    // ── Notifications ─────────────────────────────────────
    Route::get('/notifications',              [ProductController::class, 'notifications'])->middleware('can:notification-view');
    Route::patch('/notifications/{id}/read',  [ProductController::class, 'markNotificationRead'])->middleware('can:notification-mark-read');

    // ── Seller Ratings ────────────────────────────────────
    Route::get('/sellers/{sellerId}/rating/me', [SellerRatingController::class, 'myStatus']);
    Route::post('/sellers/{sellerId}/rating',   [SellerRatingController::class, 'store'])->middleware('can:rating-create');

    // ── Profile ────────────────────────────────────────────
    Route::get('/profile',          [ProductController::class, 'getProfile']);
    Route::post('/profile',         [ProductController::class, 'updateProfile']);
});

// ── MESSAGES (Authenticated Users) ──────────────────────────
Route::middleware('auth:sanctum')->prefix('messages')->group(function () {
    Route::get('/unread-count',      [MessageController::class, 'unreadCount'])->middleware('can:message-view');
    Route::get('/',                  [MessageController::class, 'index'])->middleware('can:message-view');
    Route::get('/thread/{otherId}',  [MessageController::class, 'show'])->middleware('can:message-view');
    Route::post('/',                 [MessageController::class, 'send'])->middleware('can:message-send');
    Route::patch('/{id}/read',       [MessageController::class, 'markRead'])->middleware('can:message-view');
});

// ── NOTIFICATIONS (Authenticated Users) ──────────────────────
Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
    Route::get('/unread-count',      [NotificationController::class, 'unreadCount'])->middleware('can:notification-view');
    Route::patch('/read-all',        [NotificationController::class, 'markAllRead'])->middleware('can:notification-mark-read');
    Route::get('/',                  [NotificationController::class, 'index'])->middleware('can:notification-view');
    Route::patch('/{id}/read',       [NotificationController::class, 'markRead'])->middleware('can:notification-mark-read');
    Route::delete('/{id}',           [NotificationController::class, 'destroy'])->middleware('can:notification-delete');
});