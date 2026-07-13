<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Single-row "singleton" settings table — the whole platform shares one
     * configuration record (id = 1), read/written via Settings::current().
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            // General
            $table->string('platform_name')->default('E-Waste Mart');
            $table->string('support_email')->nullable();

            // Marketplace rules
            $table->boolean('auto_approve_listings')->default(false);
            $table->unsignedTinyInteger('max_images_per_listing')->default(5);
            $table->unsignedInteger('max_listing_price')->nullable(); // null = no cap

            // Security
            $table->unsignedTinyInteger('min_password_length')->default(8);
            $table->boolean('require_strong_password')->default(false);

            // Notifications
            $table->boolean('notify_admins_on_new_user')->default(true);
            $table->boolean('notify_admins_on_new_listing')->default(true);

            // Maintenance mode
            $table->boolean('maintenance_mode')->default(false);
            $table->text('maintenance_message')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
