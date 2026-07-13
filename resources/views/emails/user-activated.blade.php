<div style="font-family: sans-serif; max-width: 480px; margin: 0 auto;">
    <h2 style="color: #16a34a;">Welcome back, {{ $user->name }} 🎉</h2>
    <p>Good news — your E-Waste Mart account has been reactivated.</p>
    <p style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 16px; font-weight: bold; color: #14532d;">
        You can now log in and use your account as normal.
    </p>
    <p style="text-align: center; margin: 28px 0;">
        <a href="{{ $loginUrl }}"
           style="background: #16a34a; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 12px; font-weight: bold; display: inline-block;">
            Log in to my account
        </a>
    </p>
    <p style="color: #6b7280; font-size: 13px;">If you weren't expecting this, please contact our support team.</p>
</div>
