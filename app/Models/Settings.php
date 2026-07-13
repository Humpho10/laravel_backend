<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Settings extends Model
{
    protected $table = 'settings';

    protected $fillable = [
        'platform_name',
        'support_email',
        'support_phone',
        'contact_address',
        'tagline',
        'facebook_url',
        'twitter_url',
        'instagram_url',
        'auto_approve_listings',
        'max_images_per_listing',
        'max_image_upload_size_kb',
        'max_listing_price',
        'min_listing_price',
        'min_password_length',
        'require_strong_password',
        'require_email_verification',
        'allow_google_login',
        'allow_public_registration',
        'max_login_attempts',
        'lockout_duration_minutes',
        'session_lifetime_minutes',
        'notify_admins_on_new_user',
        'notify_admins_on_new_listing',
        'notify_admins_on_new_message',
        'maintenance_mode',
        'maintenance_message',
        'maintenance_allow_admin_login',
        'maintenance_allow_pm_login',
        'maintenance_allow_user_login',
    ];

    protected $casts = [
        'auto_approve_listings'         => 'boolean',
        'require_strong_password'       => 'boolean',
        'require_email_verification'    => 'boolean',
        'allow_google_login'            => 'boolean',
        'allow_public_registration'     => 'boolean',
        'notify_admins_on_new_user'     => 'boolean',
        'notify_admins_on_new_listing'  => 'boolean',
        'notify_admins_on_new_message'  => 'boolean',
        'maintenance_mode'              => 'boolean',
        'maintenance_allow_admin_login' => 'boolean',
        'maintenance_allow_pm_login'    => 'boolean',
        'maintenance_allow_user_login'  => 'boolean',
        'max_images_per_listing'       => 'integer',
        'max_image_upload_size_kb'     => 'integer',
        'max_listing_price'            => 'integer',
        'min_listing_price'            => 'integer',
        'min_password_length'          => 'integer',
        'max_login_attempts'           => 'integer',
        'lockout_duration_minutes'     => 'integer',
        'session_lifetime_minutes'     => 'integer',
    ];

    /**
     * The whole platform shares a single settings row (id = 1). This helper
     * fetches it, creating it with sensible defaults on first use so every
     * caller can rely on it always existing.
     */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    /**
     * Builds the Laravel validation rule fragment for a password, honoring
     * the configured minimum length and (optionally) a strength requirement
     * of at least one letter and one number.
     *
     * @return array<int, string>
     */
    public function passwordRules(): array
    {
        $rules = ['required', 'string', "min:{$this->min_password_length}"];

        if ($this->require_strong_password) {
            $rules[] = 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/';
        }

        return $rules;
    }
}
