<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use \Illuminate\Database\Console\Seeds\WithoutModelEvents;

    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ── PERMISSIONS ───────────────────────────────────────
        $permissions = [
            // User management (Super Admin)
            'user-list',
            'user-create',
            'user-edit',
            'user-delete',
            'user-activate',
            'user-deactivate',

            // Admin management (Super Admin)
            'admin-list',
            'admin-create',
            'admin-edit',
            'admin-delete',

            // Role management (Super Admin)
            'role-list',
            'role-create',
            'role-edit',
            'role-delete',

            // Permission management (Super Admin)
            'permission-list',
            'permission-create',
            'permission-delete',

            // Category management (Admin)
            'category-list',
            'category-create',
            'category-edit',
            'category-delete',

            // Product Manager management (Admin)
            'pm-list',
            'pm-create',
            'pm-edit',
            'pm-delete',
            'pm-assign-category',
            'pm-remove-category',

            // Product/Listing management (Admin + PM)
            'product-list',
            'product-approve',
            'product-reject',
            'product-view',

            // Product management (own listings — Regular User)
            'product-create',
            'product-edit',
            'product-delete',

            // Messaging (Admin + PM + Regular User)
            'message-send',
            'message-view',

            // Notifications
            'notification-view',
            'notification-mark-read',
            'notification-delete',

            // Audit trail (Super Admin + Admin)
            'audit-list',

            // Dashboard
            'dashboard-view',

            // Seller ratings (Regular User)
            'rating-create',
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission],
                ['guard_name' => 'web']
            );
        }

        // ── ROLES ─────────────────────────────────────────────

        // Super Admin — gets ALL permissions
        $superAdminRole = Role::updateOrCreate(
            ['name' => 'Super-Admin'],
            ['guard_name' => 'web']
        );
        $superAdminRole->syncPermissions(Permission::all());

        // Admin — manages marketplace operations
        $adminRole = Role::updateOrCreate(
            ['name' => 'Admin'],
            ['guard_name' => 'web']
        );
        $adminRole->syncPermissions([
            'user-list',
            'user-activate',
            'user-deactivate',
            'category-list',
            'category-create',
            'category-edit',
            'category-delete',
            'pm-list',
            'pm-create',
            'pm-edit',
            'pm-delete',
            'pm-assign-category',
            'pm-remove-category',
            'product-list',
            'product-approve',
            'product-reject',
            'product-view',
            'message-send',
            'message-view',
            'notification-view',
            'notification-mark-read',
            'audit-list',
            'dashboard-view',
        ]);

        // Product Manager — approves/rejects listings in assigned categories
        $pmRole = Role::updateOrCreate(
            ['name' => 'Product-Manager'],
            ['guard_name' => 'web']
        );
        $pmRole->syncPermissions([
            'product-list',
            'product-approve',
            'product-reject',
            'product-view',
            'message-send',
            'message-view',
            'notification-view',
            'notification-mark-read',
            'dashboard-view',
        ]);

        // Regular User — buyers/sellers
        $userRole = Role::updateOrCreate(
            ['name' => 'User'],
            ['guard_name' => 'web']
        );
        $userRole->syncPermissions([
            'product-list',
            'product-view',
            'product-create',
            'product-edit',
            'product-delete',
            'message-send',
            'message-view',
            'notification-view',
            'notification-mark-read',
            'notification-delete',
            'dashboard-view',
            'rating-create',
        ]);

        // ── USERS ─────────────────────────────────────────────

        // Super Admin — seeded only, never created through the app
        $superAdmin = User::updateOrCreate(
            ['email' => 'super@ewaste.org'],
            [
                'name'              => 'Super Admin',
                'password'          => Hash::make('password123'),
                'phone'             => null,
                'location'          => null,
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );
        $superAdmin->syncRoles($superAdminRole);

        // Test Admin — created by Super Admin in real usage
        $admin = User::updateOrCreate(
            ['email' => 'admin@ewaste.org'],
            [
                'name'              => 'Test Admin',
                'password'          => Hash::make('password123'),
                'phone'             => '0700000001',
                'location'          => 'Kampala',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );
        $admin->syncRoles($adminRole);

        // Test Product Manager — created by Admin in real usage
        $pm = User::updateOrCreate(
            ['email' => 'pm@ewaste.org'],
            [
                'name'              => 'Test Product Manager',
                'password'          => Hash::make('password123'),
                'phone'             => '0700000002',
                'location'          => 'Kampala',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );
        $pm->syncRoles($pmRole);

        // Test Regular User
        $testUser = User::updateOrCreate(
            ['email' => 'user@ewaste.org'],
            [
                'name'              => 'Test User',
                'password'          => Hash::make('password123'),
                'phone'             => '0700000003',
                'location'          => 'Kampala',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );
        $testUser->syncRoles($userRole);

        // ── CATEGORIES ────────────────────────────────────────
        $this->call(CategorySeeder::class);

        $this->command->info('✅ Roles, permissions, and test users seeded successfully!');
    }
}