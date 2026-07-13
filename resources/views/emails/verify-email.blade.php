<div style="font-family: sans-serif; max-width: 480px; margin: 0 auto;">
    <h2 style="color: #0b2545;">Welcome to E-Waste Mart, {{ $name }} 👋</h2>
    <p>Thanks for signing up. Please confirm your email address to unlock your account.</p>
    <p style="text-align: center; margin: 28px 0;">
        <a href="{{ $verifyUrl }}"
           style="background: #2563eb; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 12px; font-weight: bold; display: inline-block;">
            Verify my email
        </a>
    </p>
    <p style="color: #6b7280; font-size: 13px;">This link expires in 24 hours. If the button doesn't work, copy and paste this URL into your browser:</p>
    <p style="color: #2563eb; font-size: 12px; word-break: break-all;">{{ $verifyUrl }}</p>
    <p style="color: #6b7280; font-size: 13px;">If you didn't create this account, you can safely ignore this email.</p>
</div>
