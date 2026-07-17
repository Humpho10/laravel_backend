<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use App\Models\PasswordResetOtp;
use App\Mail\PasswordResetOtpMail;
use App\Models\EmailVerification;
use App\Mail\VerifyEmailMail;
use Illuminate\Support\Facades\Mail;
use App\Helpers\Mailer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use App\Models\Settings;
use Illuminate\Support\Facades\RateLimiter;
use App\Helpers\EmailVerifier;

class AuthController extends Controller
{
    // Register a new user
    public function register(Request $request)
    {
        if (!Settings::current()->allow_public_registration) {
            return response()->json([
                'message' => 'New account registration is currently disabled. Please contact support.',
            ], 403);
        }

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'phone'    => 'required|string|max:20',
            'location' => 'required|string|max:100',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => array_merge(Settings::current()->passwordRules(), ['confirmed']),
        ], [
            'password.regex' => 'Password must include at least one letter and one number.',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'phone'    => $validated['phone'],
            'location' => $validated['location'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        // Assign the default buyer/seller role so new users get dashboard access.
        $user->assignRole('User');

        $this->sendVerificationEmail($user);

        // No token/auto-login — the user must verify their email, then sign in.
        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account before signing in.',
            'email'   => $user->email,
        ], 201);
    }

    // Login
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $settings = Settings::current();

        // Brute-force protection — lock out after too many failed attempts
        // from this email+IP combination, for a configurable cooldown.
        $throttleKey = 'login:' . Str::lower($request->email) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, $settings->max_login_attempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Please try again in " . ceil($seconds / 60) . " minute(s)."],
            ]);
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            RateLimiter::hit($throttleKey, $settings->lockout_duration_minutes * 60);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated. Please contact support.',
            ], 403);
        }

        // Staff accounts (Admin, Product-Manager) must always verify their
        // email before first login, regardless of the site-wide toggle —
        // that toggle only governs public buyer/seller registration.
        $requiresVerification = $settings->require_email_verification || $user->hasAnyRole(['Admin', 'Product-Manager']);

        if ($requiresVerification && !$user->email_verified_at) {
            return response()->json([
                'message' => 'Please verify your email address before signing in. Check your inbox for the verification link.',
                'email_unverified' => true,
            ], 403);
        }

        $user->load('roles');
        $role = $user->roles->first()?->name ?? 'user';

        if ($blocked = $this->maintenanceBlockMessage($settings, $role)) {
            return response()->json(['message' => $blocked, 'maintenance_mode' => true], 503);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $permissions = $user->getAllPermissions()->pluck('name');

        return response()->json([
            'message'     => 'Login successful',
            'user'        => $user,
            'token'       => $token,
            'role'        => $role,
            'permissions' => $permissions,
        ]);
    }

    /**
     * When maintenance mode is on, only the Super Admin can always sign in.
     * Every other role is blocked unless the Super Admin has explicitly
     * re-opened sign-in for that specific role from Settings > Maintenance.
     * Returns a user-facing message when the login should be blocked, or
     * null when it's fine to proceed.
     */
    private function maintenanceBlockMessage(Settings $settings, string $role): ?string
    {
        if (!$settings->maintenance_mode || $role === 'Super-Admin') {
            return null;
        }

        $allowed = match ($role) {
            'Admin'            => $settings->maintenance_allow_admin_login,
            'Product-Manager'  => $settings->maintenance_allow_pm_login,
            default            => $settings->maintenance_allow_user_login,
        };

        if ($allowed) {
            return null;
        }

        return $settings->maintenance_message
            ?: "{$settings->platform_name} is currently down for maintenance. Please try again shortly.";
    }

    // Sign in / sign up with a Google ID token from Google Identity Services (GSI).
    public function google(Request $request)
    {
        if (!Settings::current()->allow_google_login) {
            return response()->json([
                'message' => 'Google sign-in is currently disabled for this platform.',
            ], 503);
        }

        $request->validate([
            'credential' => 'required|string',
        ]);

        $clientId = config('services.google.client_id');

        if (!$clientId) {
            return response()->json([
                'message' => 'Google sign-in is not configured on the server.',
            ], 503);
        }

        // Verify the ID token with Google's tokeninfo endpoint — avoids needing
        // a full OAuth library just to validate a signed JWT.
        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->retry(2, 200, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => $request->credential,
                ]);
        } catch (ConnectionException $e) {
            // Guzzle appends the failing URL to its message, which carries the
            // caller's live id_token — redact it before this reaches the log.
            $reason = preg_replace('/id_token=[\w.\-]+/', 'id_token=[redacted]', $e->getMessage());
            Log::error('Google tokeninfo unreachable: ' . $reason);

            return response()->json([
                'message' => 'Could not reach Google to verify your sign-in. Please try again in a moment.',
            ], 503);
        }

        if (!$response->ok()) {
            return response()->json([
                'message' => 'Invalid or expired Google credential.',
            ], 401);
        }

        $payload = $response->json();

        if (($payload['aud'] ?? null) !== $clientId) {
            return response()->json([
                'message' => 'Google credential was not issued for this application.',
            ], 401);
        }

        if (($payload['email_verified'] ?? 'false') !== 'true') {
            return response()->json([
                'message' => 'Your Google email address is not verified.',
            ], 422);
        }

        $email = $payload['email'] ?? null;
        $name  = $payload['name'] ?? explode('@', $email)[0];

        if (!$email) {
            return response()->json([
                'message' => 'Could not read an email address from your Google account.',
            ], 422);
        }

        $user = User::where('email', $email)->first();

        if (!$user && !Settings::current()->allow_public_registration) {
            return response()->json([
                'message' => 'New account registration is currently disabled. Please contact support.',
            ], 403);
        }

        if (!$user) {
            $user = User::create([
                'name'              => $name,
                'email'             => $email,
                'password'          => Hash::make(Str::random(32)),
                'email_verified_at' => now(),
                'is_active'         => true,
            ]);
            $user->assignRole('User');
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated. Please contact support.',
            ], 403);
        }

        $user->load('roles');
        $role = $user->roles->first()?->name ?? 'user';

        if ($blocked = $this->maintenanceBlockMessage(Settings::current(), $role)) {
            return response()->json(['message' => $blocked, 'maintenance_mode' => true], 503);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $permissions = $user->getAllPermissions()->pluck('name');

        return response()->json([
            'message'     => 'Login successful',
            'user'        => $user,
            'token'       => $token,
            'role'        => $role,
            'permissions' => $permissions,
        ]);
    }

    // Get currently logged in user
    public function me(Request $request)
    {
        $user = $request->user()->load('roles');
        $permissions = $user->getAllPermissions()->pluck('name');

        return response()->json([
            'user'        => $user,
            'permissions' => $permissions,
        ]);
    }

    // Logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        // Don't reveal whether the email exists — respond the same either way.
        if ($user) {
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresInSeconds = 300; // 5 minutes

            PasswordResetOtp::where('email', $request->email)->delete();
            PasswordResetOtp::create([
                'email'      => $request->email,
                'otp'        => $otp,
                'expires_at' => now()->addSeconds($expiresInSeconds),
            ]);

            Mailer::send($request->email, new PasswordResetOtpMail($otp));
        }

        return response()->json([
            'message'    => 'If that email exists, a code has been sent.',
            'expires_in' => 300,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'otp'      => 'required|digits:6',
            'password' => array_merge(Settings::current()->passwordRules(), ['confirmed']), // expects password_confirmation
        ], [
            'password.regex' => 'Password must include at least one letter and one number.',
        ]);

        $record = PasswordResetOtp::where('email', $request->email)
            ->where('otp', $request->otp)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        if ($record->expires_at->isPast()) {
            return response()->json(['message' => 'This code has expired. Please request a new one.'], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        PasswordResetOtp::where('email', $request->email)->delete();

        return response()->json(['message' => 'Password reset successfully.']);
    }

    // Confirm a user's email address using the token from the verification link.
    public function verifyEmail(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $record = EmailVerification::where('token', $request->token)->first();

        if (!$record) {
            return response()->json(['message' => 'Invalid or already-used verification link.'], 422);
        }

        if ($record->expires_at->isPast()) {
            return response()->json(['message' => 'This verification link has expired. Please request a new one.'], 422);
        }

        $user = User::where('email', $record->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        EmailVerification::where('email', $record->email)->delete();

        return response()->json(['message' => 'Email verified successfully.']);
    }

    // Resend the verification email to the currently authenticated user.
    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'This account is already verified.'], 400);
        }

        $this->sendVerificationEmail($user);

        return response()->json(['message' => 'Verification email sent.']);
    }

    // Public — resend the verification link by email, no auth required.
    // Mirrors forgotPassword()'s non-enumerating pattern: someone blocked at
    // login for being unverified has no auth token to call the authenticated
    // resend endpoint above with, so this is the only way they can recover
    // a lost or expired link.
    public function resendVerificationPublic(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if ($user && !$user->email_verified_at) {
            EmailVerifier::send($user);
        }

        return response()->json([
            'message' => 'If that email exists and needs verifying, a new link has been sent.',
        ]);
    }

    // Generate a fresh verification token for the user and email it to them.
    private function sendVerificationEmail(User $user): void
    {
        EmailVerifier::send($user);
    }
}