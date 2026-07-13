<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Lets the Super Admin selectively re-open sign-in for specific roles
     * while maintenance mode is active — e.g. let Admins back in to help
     * verify a fix while Product Managers and regular Users stay locked out.
     * Super Admin can always sign in regardless of these flags.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('maintenance_allow_admin_login')->default(false)->after('maintenance_message');
            $table->boolean('maintenance_allow_pm_login')->default(false)->after('maintenance_allow_admin_login');
            $table->boolean('maintenance_allow_user_login')->default(false)->after('maintenance_allow_pm_login');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['maintenance_allow_admin_login', 'maintenance_allow_pm_login', 'maintenance_allow_user_login']);
        });
    }
};
