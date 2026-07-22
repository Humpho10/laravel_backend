<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// The contact_messages table (2026_07_16_120000) shipped without the
// contact-view/contact-reply permissions it's guarded by, so Admin/Super-Admin
// could never actually reach /manager/contact-messages. This grants them
// additively — it never touches any other permission a role already has.
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'contact-view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'contact-reply', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', 'Admin')->first()?->givePermissionTo(['contact-view', 'contact-reply']);
        Role::where('name', 'Super-Admin')->first()?->givePermissionTo(['contact-view', 'contact-reply']);
    }

    public function down(): void
    {
        Role::where('name', 'Admin')->first()?->revokePermissionTo(['contact-view', 'contact-reply']);
        Role::where('name', 'Super-Admin')->first()?->revokePermissionTo(['contact-view', 'contact-reply']);

        Permission::where('name', 'contact-view')->delete();
        Permission::where('name', 'contact-reply')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
