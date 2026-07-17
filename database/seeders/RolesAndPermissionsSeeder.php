<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\Hash;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Permissions ──────────────────────────────────────
        $permissions = [
            // ── SUPER ADMIN PERMISSIONS ──────────────────────
            // User Management
            'user-list',
            'user-create',
            'user-edit',
            'user-delete',
            'user-activate',
            'user-deactivate',

            // Admin Management
            'admin-list',
            'admin-create',
            'admin-edit',
            'admin-delete',

            // Role Management
            'role-list',
            'role-create',
            'role-edit',
            'role-delete',

            // Permission Management
            'permission-list',
            'permission-create',
            'permission-edit',
            'permission-delete',

            // Audit Trail
            'audit-list',

            // Dashboard
            'dashboard-view',

            // ── ADMIN / MANAGER PERMISSIONS ──────────────────
            // Product Manager Management
            'pm-list',
            'pm-create',
            'pm-edit',
            'pm-delete',
            'pm-assign-category',
            'pm-remove-category',

            // Category Management
            'category-list',
            'category-create',
            'category-edit',
            'category-delete',

            // Product Management (Admin)
            'product-list',
            'product-view',
            'product-approve',
            'product-reject',

            // Contact Messages (Admin)
            'contact-view',
            'contact-reply',

            // ── PRODUCT MANAGER PERMISSIONS ──────────────────
            // Product Management (scoped)
            'product-list',
            'product-view',
            'product-approve',
            'product-reject',

            // ── SELLER / BUYER PERMISSIONS ──────────────────
            // Product Management (own listings)
            'product-create',
            'product-edit',
            'product-delete',
            'product-list', // View own listings

            // Messaging
            'message-view',
            'message-send',

            // Notifications
            'notification-view',
            'notification-mark-read',
            'notification-delete',
        ];

        // Remove duplicates and create permissions
        $uniquePermissions = array_unique($permissions);

        foreach ($uniquePermissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission],
                ['guard_name' => 'web']
            );
        }

        // ── Roles ─────────────────────────────────────────────

        // ── SUPER ADMIN ──────────────────────────────────────
        $superAdminRole = Role::updateOrCreate(['name' => 'Super-Admin', 'guard_name' => 'web']);
        $superAdminRole->syncPermissions(Permission::all());

        // ── ADMIN ─────────────────────────────────────────────
        $adminRole = Role::updateOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([
            'pm-list',
            'pm-create',
            'pm-edit',
            'pm-delete',
            'pm-assign-category',
            'pm-remove-category',
            'category-list',
            'category-create',
            'category-edit',
            'category-delete',
            'product-list',
            'product-view',
            'product-approve',
            'product-reject',
            'user-list',
            'user-activate',
            'user-deactivate',
            'dashboard-view',
            'audit-list',
            'message-view',
            'message-send',
            'notification-view',
            'notification-mark-read',
            'notification-delete',
            'contact-view',
            'contact-reply',
        ]);

        // ── PRODUCT MANAGER ──────────────────────────────────
        $pmRole = Role::updateOrCreate(['name' => 'Product-Manager', 'guard_name' => 'web']);
        $pmRole->syncPermissions([
            'product-list',
            'product-view',
            'product-approve',
            'product-reject',
            'message-view',
            'message-send',
            'notification-view',
            'notification-mark-read',
        ]);

        // ── REGULAR USER (Seller/Buyer) ─────────────────────
        // NEW: This role gives basic permissions to regular users
        $userRole = Role::updateOrCreate(['name' => 'User', 'guard_name' => 'web']);
        $userRole->syncPermissions([
            // Product Management (own listings)
            'product-create',
            'product-edit',
            'product-delete',
            'product-list',

            // Messaging
            'message-view',
            'message-send',

            // Notifications
            'notification-view',
            'notification-mark-read',
            'notification-delete',

            // Dashboard (basic)
            'dashboard-view',
        ]);

        // ── USERS ─────────────────────────────────────────────

        // ── Super Admin ──────────────────────────────────────
        $superAdmin = User::updateOrCreate(
            ['email' => 'super@ewaste.org'],
            [
                'name'              => 'Super Admin',
                'phone'             => '1234567890',
                'location'          => 'Headquarters',
                'password'          => Hash::make('password123'),
                'email_verified_at' => now(),
                'is_active'         => true,
            ]
        );
        $superAdmin->syncRoles($superAdminRole);

        // ── Admin (Operations Manager) ──────────────────────
        $admin = User::updateOrCreate(
            ['email' => 'admin@ewaste.org'],
            [
                'name'              => 'Test Admin',
                'phone'             => '1234567891',
                'location'          => 'Operations Office',
                'password'          => Hash::make('password123'),
                'email_verified_at' => now(),
                'is_active'         => true,
            ]
        );
        $admin->syncRoles($adminRole);

        // ── Product Manager ──────────────────────────────────
        $pm = User::updateOrCreate(
            ['email' => 'pm@ewaste.org'],
            [
                'name'              => 'Test Product Manager',
                'phone'             => '1234567892',
                'location'          => 'Category Office',
                'password'          => Hash::make('password123'),
                'email_verified_at' => now(),
                'is_active'         => true,
            ]
        );
        $pm->syncRoles($pmRole);

        // ── Regular User (Seller/Buyer) ─────────────────────
        $user = User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name'              => 'Test User',
                'phone'             => '1234567893',
                'location'          => 'Customer Address',
                'password'          => Hash::make('password'),
                'email_verified_at' => now(),
                'is_active'         => true,
            ]
        );
        // 🔥 NEW: Assign the 'User' role so they have basic permissions
        $user->syncRoles($userRole);

        $this->command->info('✅ Roles and permissions seeded successfully!');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('🔑 Super Admin:     super@ewaste.org / password123');
        $this->command->info('👔 Admin:           admin@ewaste.org / password123');
        $this->command->info('📦 Product Manager: pm@ewaste.org / password123');
        $this->command->info('👤 Regular User:    test@example.com / password');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('📊 Total Permissions: ' . Permission::count());
        $this->command->info('👥 Total Roles: ' . Role::count());
        $this->command->info('👤 Total Users: ' . User::count());
    }
}