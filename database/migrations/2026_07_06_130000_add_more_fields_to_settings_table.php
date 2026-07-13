<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // ── Branding & contact (footer, public pages) ──────────
            $table->string('support_phone')->nullable()->after('support_email');
            $table->string('contact_address')->nullable()->after('support_phone');
            $table->string('tagline')->nullable()->after('contact_address');
            $table->string('facebook_url')->nullable()->after('tagline');
            $table->string('twitter_url')->nullable()->after('facebook_url');
            $table->string('instagram_url')->nullable()->after('twitter_url');

            // ── Marketplace rules ───────────────────────────────────
            $table->unsignedInteger('min_listing_price')->nullable()->after('max_listing_price');
            $table->unsignedInteger('max_image_upload_size_kb')->default(2048)->after('max_images_per_listing');

            // ── Security & access ────────────────────────────────────
            $table->boolean('require_email_verification')->default(false)->after('require_strong_password');
            $table->boolean('allow_google_login')->default(true)->after('require_email_verification');
            $table->boolean('allow_public_registration')->default(true)->after('allow_google_login');
            $table->unsignedTinyInteger('max_login_attempts')->default(5)->after('allow_public_registration');
            $table->unsignedTinyInteger('lockout_duration_minutes')->default(15)->after('max_login_attempts');
            $table->unsignedInteger('session_lifetime_minutes')->nullable()->after('lockout_duration_minutes'); // null = never expires

            // ── Notifications ─────────────────────────────────────
            $table->boolean('notify_admins_on_new_message')->default(false)->after('notify_admins_on_new_listing');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'support_phone',
                'contact_address',
                'tagline',
                'facebook_url',
                'twitter_url',
                'instagram_url',
                'min_listing_price',
                'max_image_upload_size_kb',
                'require_email_verification',
                'allow_google_login',
                'allow_public_registration',
                'max_login_attempts',
                'lockout_duration_minutes',
                'session_lifetime_minutes',
                'notify_admins_on_new_message',
            ]);
        });
    }
};
