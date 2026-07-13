<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Category;
use App\Models\AuditTrail;
use App\Models\Message;
use App\Models\Settings;
use App\Models\ProductManagerAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Auth;
use App\Helpers\AuditLogger;
use Spatie\Permission\PermissionRegistrar;
use Carbon\Carbon;

class AdminController extends Controller
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

    // ── Stats ─────────────────────────────────────────────
    public function stats()
    {
        if (!$this->user->can('dashboard-view') &&
            !$this->user->can('user-list') &&
            !$this->user->can('role-list') &&
            !$this->user->can('permission-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view dashboard stats.'], 403);
        }

        try {
            $now = Carbon::now();

            // Signups per day for the last 14 days — feeds the growth chart.
            $userGrowth = collect(range(13, 0))->map(function ($daysAgo) use ($now) {
                $date = $now->copy()->subDays($daysAgo);
                return [
                    'date'  => $date->format('M j'),
                    'users' => User::whereDate('created_at', $date->toDateString())->count(),
                ];
            })->values();

            // How the user base splits across roles — feeds the donut chart.
            $roleDistribution = Role::all()->map(function ($role) {
                return [
                    'name'  => $role->name,
                    'value' => User::role($role->name)->count(),
                ];
            })->filter(fn ($r) => $r['value'] > 0)->values();

            // Listing pipeline health — feeds the progress bars.
            $listingStats = [
                'total'    => Product::count(),
                'pending'  => Product::where('status', 'pending')->count(),
                'approved' => Product::where('status', 'approved')->count(),
                'rejected' => Product::where('status', 'rejected')->count(),
            ];

            // Busiest categories — feeds the bar chart.
            $categoryBreakdown = Category::withCount('products')
                ->orderByDesc('products_count')
                ->take(6)
                ->get()
                ->map(fn ($c) => ['name' => $c->name, 'count' => $c->products_count])
                ->values();

            // New users in the last 7 days, for a small trend indicator.
            $newUsersThisWeek = User::where('created_at', '>=', $now->copy()->subDays(7))->count();
            $newUsersPrevWeek = User::whereBetween('created_at', [$now->copy()->subDays(14), $now->copy()->subDays(7)])->count();

            // CO2 offset estimation (0.5kg per approved product, average e-waste weight ~5kg)
            $approvedProducts = Product::where('status', 'approved')->count();
            $co2Offset = round($approvedProducts * 5 * 0.5, 1); // kg of CO2 saved

            return response()->json([
                'total_users'         => User::count(),
                'total_admins'        => User::role('Admin')->count(),
                'total_managers'      => User::role('Product-Manager')->count(),
                'total_roles'         => Role::count(),
                'total_permissions'   => Permission::count(),
                'listing_stats'       => $listingStats,
                'co2_offset'          => $co2Offset,
                'recent_users'        => User::with('roles')
                                            ->latest()
                                            ->take(5)
                                            ->get(),
                'user_growth'         => $userGrowth,
                'role_distribution'   => $roleDistribution,
                'category_breakdown'  => $categoryBreakdown,
                'new_users_this_week' => $newUsersThisWeek,
                'new_users_prev_week' => $newUsersPrevWeek,
                'recent_activity'     => AuditTrail::with('user:id,name')->latest()->take(6)->get()->map(fn ($a) => [
                    'id'         => $a->audit_id,
                    'user'       => $a->user?->name ?? 'System',
                    'action'     => $a->action,
                    'table'      => $a->table_name,
                    'created_at' => $a->created_at,
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }

    // ── All Users ─────────────────────────────────────────
    public function getUsers(Request $request)
    {
        if (!$this->user->can('user-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view users.'], 403);
        }

        // Product-Manager accounts also carry their assigned categories, so
        // the Super Admin can see at a glance which categories each PM
        // covers — every other role just gets an empty relation.
        $query = User::with(['roles', 'productManagerAssignments.category']);

        if ($request->has('role') && $request->role !== 'all') {
            $query->role($request->role);
        }

        if ($request->has('search') && $request->search) {
            $query->where(function($q) use ($request) {
                $q->where('name',  'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        $users = $query->latest()->get();
        return response()->json(['users' => $users]);
    }

    public function createUser(Request $request)
    {
        if (!$this->user->can('user-create')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to create users.'], 403);
        }

        $settings = Settings::current();

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users',
            'password' => $settings->passwordRules(),
            'role'     => 'nullable|string|exists:roles,name',
        ], [
            'password.regex' => 'Password must include at least one letter and one number.',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        if (!empty($validated['role'])) {
            $user->assignRole($validated['role']);
        }

        if ($settings->notify_admins_on_new_user) {
            $this->notifySuperAdmins(
                'user_created',
                "New user \"{$user->name}\" ({$user->email}) was created.",
                $user->id
            );
        }

        return response()->json([
            'message' => 'User created successfully',
            'user'    => $user->load('roles'),
        ], 201);
    }

    public function updateUser(Request $request, $id)
    {
        if (!$this->user->can('user-edit')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to edit users.'], 403);
        }

        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'role'  => 'nullable|string|exists:roles,name',
        ]);

        $user->update([
            'name'  => $validated['name']  ?? $user->name,
            'email' => $validated['email'] ?? $user->email,
        ]);

        if (array_key_exists('role', $validated)) {
            $user->syncRoles($validated['role'] ? [$validated['role']] : []);
        }

        return response()->json([
            'message' => 'User updated successfully',
            'user'    => $user->load('roles'),
        ]);
    }

    public function deleteUser($id)
    {
        if (!$this->user->can('user-delete')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to delete users.'], 403);
        }

        $user = User::findOrFail($id);

        // Business rule: cannot delete own account
        if ($user->id === Auth::id()) {
            return response()->json([
                'message' => 'You cannot delete your own account.'
            ], 403);
        }

        // Business rule: cannot delete a Super Admin
        if ($user->hasRole('Super-Admin')) {
            return response()->json([
                'message' => 'Super Admin cannot be deleted.'
            ], 403);
        }

        $userName = $user->name;
        $userEmail = $user->email;

        $user->delete();

        $this->notifySuperAdmins(
            'user_deleted',
            "User \"{$userName}\" ({$userEmail}) was deleted.",
            $id
        );

        return response()->json(['message' => 'User deleted successfully']);
    }

    // ── Managers (Admins) ─────────────────────────────────
    public function getAdmins()
    {
        if (!$this->user->can('admin-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view admins.'], 403);
        }

        try {
            $admins = User::role('Admin')->with('roles')->latest()->get();
            return response()->json(['admins' => $admins]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }

    // ── Product Managers (read-only oversight) ─────────────
    // Super Admin can see every Product-Manager account and which
    // categories they're assigned to, but creating/editing/removing PMs
    // and their category assignments stays an Admin-only responsibility
    // (see ManagerController) — this endpoint is deliberately list-only.
    public function getProductManagers()
    {
        if (!$this->user->can('pm-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view product managers.'], 403);
        }

        try {
            $productManagers = User::role('Product-Manager')->latest()->get();

            foreach ($productManagers as $pm) {
                $pm->assignments = ProductManagerAssignment::where('product_manager_id', $pm->id)
                    ->with('category:category_id,name')
                    ->get();
            }

            return response()->json(['product_managers' => $productManagers]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }

    public function createAdmin(Request $request)
    {
        if (!$this->user->can('admin-create')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to create admins.'], 403);
        }

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users',
            'password' => Settings::current()->passwordRules(),
        ], [
            'password.regex' => 'Password must include at least one letter and one number.',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole('Admin');

        $this->notifySuperAdmins(
            'admin_created',
            "New Admin account created for \"{$user->name}\" ({$user->email}).",
            $user->id
        );

        return response()->json([
            'message' => 'Admin created successfully',
            'user'    => $user->load('roles'),
        ], 201);
    }

    public function deleteAdmin($id)
    {
        if (!$this->user->can('admin-delete')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to delete admins.'], 403);
        }

        $user = User::findOrFail($id);

        if (!$user->hasRole('Admin')) {
            return response()->json(['message' => 'User is not an Admin.'], 403);
        }

        $userName = $user->name;

        $user->delete();

        $this->notifySuperAdmins(
            'admin_deleted',
            "Admin account for \"{$userName}\" was removed.",
            $id
        );

        return response()->json(['message' => 'Admin deleted successfully']);
    }

    // ── Roles ─────────────────────────────────────────────
    public function getRoles()
    {
        if (!$this->user->can('role-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view roles.'], 403);
        }

        $roles = Role::with('permissions')->get()->map(function($role) {
            return [
                'id'          => $role->id,
                'name'        => $role->name,
                'guard_name'  => $role->guard_name,
                'permissions' => $role->permissions,
                'users_count' => User::role($role->name)->count(),
            ];
        });

        return response()->json(['roles' => $roles]);
    }

    public function createRole(Request $request)
    {
        if (!$this->user->can('role-create')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to create roles.'], 403);
        }

        $validated = $request->validate([
            'name'        => 'required|string|unique:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);

        if (!empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        $this->notifySuperAdmins(
            'role_created',
            "New role \"{$role->name}\" was created.",
            $role->id
        );

        return response()->json([
            'message' => 'Role created successfully',
            'role'    => $role->load('permissions'),
        ], 201);
    }

    public function updateRole(Request $request, $id)
    {
        if (!$this->user->can('role-edit')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to update roles.'], 403);
        }

        $role = Role::findOrFail($id);

        $validated = $request->validate([
            'name'          => 'sometimes|string|unique:roles,name,' . $id,
            'permissions'   => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        if (!empty($validated['name'])) {
            $role->update(['name' => $validated['name']]);
        }

        if (array_key_exists('permissions', $validated)) {
            $role->syncPermissions($validated['permissions'] ?? []);
        }

        // 🔥 Clear permission cache so changes take effect immediately
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'message' => 'Role updated successfully',
            'role'    => $role->load('permissions'),
        ]);
    }

    public function deleteRole($id)
    {
        if (!$this->user->can('role-delete')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to delete roles.'], 403);
        }

        $role = Role::findOrFail($id);

        // Business rule: system roles cannot be deleted
        if (in_array($role->name, ['Super-Admin', 'Admin', 'Product-Manager'])) {
            return response()->json([
                'message' => 'System roles cannot be deleted.'
            ], 403);
        }

        $roleName = $role->name;

        $role->delete();

        $this->notifySuperAdmins(
            'role_deleted',
            "Role \"{$roleName}\" was deleted.",
            $id
        );

        return response()->json(['message' => 'Role deleted successfully']);
    }

    // ── Permissions ───────────────────────────────────────
    public function getPermissions()
    {
        if (!$this->user->can('permission-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view permissions.'], 403);
        }

        $permissions = Permission::all();
        return response()->json(['permissions' => $permissions]);
    }

    public function createPermission(Request $request)
    {
        if (!$this->user->can('permission-create')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to create permissions.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|unique:permissions,name',
        ]);

        $permission = Permission::create([
            'name'       => $validated['name'],
            'guard_name' => 'web',
        ]);

        $this->notifySuperAdmins(
            'permission_created',
            "New permission \"{$permission->name}\" was created.",
            $permission->id
        );

        return response()->json([
            'message'    => 'Permission created successfully',
            'permission' => $permission,
        ], 201);
    }

    public function deletePermission($id)
    {
        if (!$this->user->can('permission-delete')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to delete permissions.'], 403);
        }

        $permission = Permission::findOrFail($id);
        $name = $permission->name;
        $permission->delete();

        $this->notifySuperAdmins(
            'permission_deleted',
            "Permission \"{$name}\" was deleted.",
            $id
        );

        return response()->json(['message' => 'Permission deleted successfully']);
    }

    // ── Audit Trail ───────────────────────────────────────

    /**
     * Applies the shared set of audit-trail filters (used by both the
     * paginated list endpoint and the CSV export) to a query builder.
     */
    private function applyAuditFilters($query, Request $request): void
    {
        if ($request->filled('action') && $request->action !== 'all') {
            $query->where('action', $request->action);
        }

        if ($request->filled('table') && $request->table !== 'all') {
            $query->where('table_name', $request->table);
        }

        if ($request->filled('user_id') && $request->user_id !== 'all') {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('record_id')) {
            $query->where('record_id', $request->record_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('table_name', 'like', "%{$search}%")
                  ->orWhere('record_id', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }
    }

    private function mapAuditEntry($entry): array
    {
        return [
            'id'         => $entry->audit_id,
            'user'       => $entry->user?->name ?? 'System',
            'email'      => $entry->user?->email ?? '',
            'user_id'    => $entry->user_id,
            'action'     => $entry->action,
            'table'      => $entry->table_name,
            'record_id'  => $entry->record_id,
            'old_value'  => $entry->old_value,
            'new_value'  => $entry->new_value,
            'created_at' => $entry->created_at,
        ];
    }

    public function getAuditTrail(Request $request)
    {
        if (!$this->user->can('audit-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view audit trail.'], 403);
        }

        $query = AuditTrail::with('user:id,name,email');
        $this->applyAuditFilters($query, $request);

        $sort = $request->get('sort', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy('created_at', $sort);

        $perPage   = min(max((int) $request->get('per_page', 25), 5), 100);
        $paginated = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'audit' => $paginated->getCollection()->map(fn ($entry) => $this->mapAuditEntry($entry))->values(),
            'meta'  => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
            // Distinct option lists so the frontend filter dropdowns always
            // reflect what actually exists in the table (no hardcoded lists).
            'filter_options' => [
                'actions' => AuditTrail::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
                'tables'  => AuditTrail::query()->select('table_name')->distinct()->orderBy('table_name')->pluck('table_name'),
            ],
        ]);
    }

    /**
     * Streams a CSV of every audit entry matching the current filters
     * (capped at 5,000 rows so a runaway export can't hang the server).
     */
    public function exportAuditTrail(Request $request)
    {
        if (!$this->user->can('audit-list')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to export the audit trail.'], 403);
        }

        $query = AuditTrail::with('user:id,name,email');
        $this->applyAuditFilters($query, $request);

        $rows = $query->orderByDesc('created_at')->limit(5000)->get();

        $csvLine = function (array $fields) {
            return implode(',', array_map(function ($field) {
                $field = (string) ($field ?? '');
                return '"' . str_replace('"', '""', $field) . '"';
            }, $fields)) . "\n";
        };

        $csv  = $csvLine(['ID', 'User', 'Email', 'Action', 'Table', 'Record ID', 'Old Value', 'New Value', 'Date']);
        foreach ($rows as $r) {
            $csv .= $csvLine([
                $r->audit_id,
                $r->user?->name ?? 'System',
                $r->user?->email ?? '',
                $r->action,
                $r->table_name,
                $r->record_id,
                $r->old_value ? json_encode($r->old_value) : '',
                $r->new_value ? json_encode($r->new_value) : '',
                optional($r->created_at)->toDateTimeString(),
            ]);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="audit-trail-' . now()->format('Y-m-d_His') . '.csv"',
        ]);
    }

    // ── Messages (platform-wide oversight) ─────────────────
    // The Super Admin isn't a buyer or seller, so they're never a sender/
    // recipient on a listing's message thread. Instead of their own inbox
    // (which would always be empty), this gives them read-only visibility
    // into every buyer↔seller conversation on the platform — useful for
    // support and moderation.

    public function getMessages(Request $request)
    {
        if (!$this->user->can('message-view')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view messages.'], 403);
        }

        // This is listing oversight specifically — staff-to-staff messages
        // (no product_id) aren't about any listing, so they're excluded here.
        $query = Message::with([
            'sender:id,name,email',
            'recipient:id,name,email',
            'product:product_id,title,seller_id',
        ])->whereNotNull('product_id')->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('product', fn ($p) => $p->where('title', 'like', "%{$search}%"))
                  ->orWhereHas('sender', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                  ->orWhereHas('recipient', fn ($r) => $r->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        // Conversations aren't their own table — they're derived by grouping
        // messages per listing, so pagination happens in-memory after grouping.
        $conversations = $query->get()
            ->groupBy('product_id')
            ->map(function ($group) {
                $latest = $group->first();
                $participants = $group
                    ->flatMap(fn ($m) => [$m->sender, $m->recipient])
                    ->filter()
                    ->unique('id')
                    ->values()
                    ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'email' => $p->email]);

                return [
                    'product_id'      => $latest->product_id,
                    'product_title'   => $latest->product?->title ?? 'Deleted listing',
                    'seller_id'       => $latest->product?->seller_id,
                    'last_message'    => $latest->message_text,
                    'last_message_at' => $latest->created_at,
                    'message_count'   => $group->count(),
                    'unread_count'    => $group->where('is_read', false)->count(),
                    'participants'    => $participants,
                ];
            })
            ->sortByDesc('last_message_at')
            ->values();

        $total   = $conversations->count();
        $perPage = min(max((int) $request->get('per_page', 20), 5), 100);
        $page    = max((int) $request->get('page', 1), 1);
        $paged   = $conversations->forPage($page, $perPage)->values();

        return response()->json([
            'conversations' => $paged,
            'meta' => [
                'current_page' => $page,
                'last_page'    => (int) max(ceil($total / $perPage), 1),
                'per_page'     => $perPage,
                'total'        => $total,
            ],
            'total_unread' => Message::where('is_read', false)->count(),
        ]);
    }

    public function getMessageThread($productId)
    {
        if (!$this->user->can('message-view')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to view messages.'], 403);
        }

        $product  = Product::find($productId);
        $messages = Message::with(['sender:id,name,email', 'recipient:id,name,email'])
            ->where('product_id', $productId)
            ->oldest()
            ->get();

        if ($messages->isEmpty() && !$product) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        return response()->json([
            'product' => $product ? [
                'id'        => $product->product_id,
                'title'     => $product->title,
                'seller_id' => $product->seller_id,
            ] : null,
            'messages' => $messages,
            'count'    => $messages->count(),
        ]);
    }

    // ── System Settings ─────────────────────────────────────
    // Gated on 'dashboard-view' (every Super Admin already has it) rather
    // than a brand-new permission slug, so this works immediately without
    // needing a fresh permissions seed.

    public function getSettings()
    {
        if (!$this->user->can('dashboard-view')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json(['settings' => Settings::current()]);
    }

    public function updateSettings(Request $request)
    {
        if (!$this->user->can('dashboard-view')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'platform_name'                 => 'sometimes|string|max:255',
            'support_email'                 => 'nullable|email|max:255',
            'support_phone'                 => 'nullable|string|max:30',
            'contact_address'               => 'nullable|string|max:255',
            'tagline'                       => 'nullable|string|max:255',
            'facebook_url'                  => 'nullable|url|max:255',
            'twitter_url'                   => 'nullable|url|max:255',
            'instagram_url'                 => 'nullable|url|max:255',
            'auto_approve_listings'         => 'sometimes|boolean',
            'max_images_per_listing'        => 'sometimes|integer|min:1|max:10',
            'max_image_upload_size_kb'      => 'sometimes|integer|min:256|max:10240',
            'max_listing_price'             => 'nullable|integer|min:1',
            'min_listing_price'             => 'nullable|integer|min:0',
            'min_password_length'           => 'sometimes|integer|min:6|max:32',
            'require_strong_password'       => 'sometimes|boolean',
            'require_email_verification'    => 'sometimes|boolean',
            'allow_google_login'            => 'sometimes|boolean',
            'allow_public_registration'     => 'sometimes|boolean',
            'max_login_attempts'            => 'sometimes|integer|min:3|max:20',
            'lockout_duration_minutes'      => 'sometimes|integer|min:1|max:1440',
            'session_lifetime_minutes'      => 'nullable|integer|min:5',
            'notify_admins_on_new_user'     => 'sometimes|boolean',
            'notify_admins_on_new_listing'  => 'sometimes|boolean',
            'notify_admins_on_new_message'  => 'sometimes|boolean',
            'maintenance_mode'              => 'sometimes|boolean',
            'maintenance_message'           => 'nullable|string|max:1000',
            'maintenance_allow_admin_login' => 'sometimes|boolean',
            'maintenance_allow_pm_login'    => 'sometimes|boolean',
            'maintenance_allow_user_login'  => 'sometimes|boolean',
        ]);

        $settings = Settings::current();

        // Cross-field sanity check that a single field rule can't express —
        // the floor can't sit above the ceiling.
        $min = $validated['min_listing_price'] ?? $settings->min_listing_price;
        $max = $validated['max_listing_price'] ?? $settings->max_listing_price;
        if ($min !== null && $max !== null && $min > $max) {
            return response()->json([
                'message' => 'Minimum listing price cannot be greater than the maximum listing price.',
                'errors'  => ['min_listing_price' => ['Must be less than or equal to the maximum listing price.']],
            ], 422);
        }

        $oldValue = $settings->toArray();
        $settings->update($validated);

        AuditLogger::log('settings', $settings->id, 'updated', $oldValue, $settings->fresh()->toArray());

        return response()->json([
            'message'  => 'Settings updated successfully.',
            'settings' => $settings->fresh(),
        ]);
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
        $profileSettings = Settings::current();

        $passwordRule = $request->filled('password')
            ? array_merge(['sometimes'], $profileSettings->passwordRules(), ['confirmed'])
            : 'sometimes|string';

        $validated = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'phone'    => 'sometimes|string|max:20',
            'location' => 'sometimes|string|max:100',
            'password' => $passwordRule,
            'avatar'   => 'sometimes|image|mimes:jpeg,png,jpg|max:' . $profileSettings->max_image_upload_size_kb,
        ], [
            'password.regex' => 'Password must include at least one letter and one number.',
        ]);

        if (isset($validated['name']))     $user->name     = $validated['name'];
        if (isset($validated['phone']))    $user->phone    = $validated['phone'];
        if (isset($validated['location'])) $user->location = $validated['location'];
        if (isset($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();
        AuditLogger::log('users', $user->id, 'updated', [], $user->toArray());

        return response()->json(['message' => 'Profile updated successfully.', 'user' => $user], 200);
    }

    // ── PRIVATE: Notify all Super Admins ──────────────────────
    private function notifySuperAdmins(string $type, string $message, int $referenceId): void
    {
        $superAdmins = User::role('Super-Admin')->get();
        foreach ($superAdmins as $sa) {
            Notification::create([
                'user_id'      => $sa->id,
                'type'         => $type,
                'reference_id' => $referenceId,
                'message'      => $message,
                'is_read'      => false,
            ]);
        }
    }
}