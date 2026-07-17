<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Product;
use App\Models\Settings;
use Carbon\Carbon;

class StatsController extends Controller
{
    public function index()
    {
        return response()->json([
            'active_users' => User::count(),

            'listings_this_week' => Product::where('status', 'approved')
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->count(),
        ]);
    }

    // ── PUBLIC — platform settings safe to expose pre-login ────
    // Powers the storefront's maintenance-mode banner and branding;
    // never returns anything sensitive (no security/notification flags).
    public function publicSettings()
    {
        $settings = Settings::current();

        return response()->json([
            'platform_name'             => $settings->platform_name,
            'support_email'             => $settings->support_email,
            'support_phone'             => $settings->support_phone,
            'contact_address'           => $settings->contact_address,
            'tagline'                   => $settings->tagline,
            'facebook_url'              => $settings->facebook_url,
            'twitter_url'               => $settings->twitter_url,
            'instagram_url'             => $settings->instagram_url,
            'maintenance_mode'          => $settings->maintenance_mode,
            'maintenance_message'       => $settings->maintenance_message,
            // Lets the login/register screens hide options the Super Admin
            // has turned off, instead of letting the user hit a 403/503.
            'allow_google_login'        => $settings->allow_google_login,
            'allow_public_registration' => $settings->allow_public_registration,
            // Lets the "Add Photos" step cap uploads client-side to whatever
            // the Super Admin has configured, instead of a hardcoded number
            // that drifts from the server-side validation in ProductController.
            'max_images_per_listing'    => $settings->max_images_per_listing,
        ]);
    }
}