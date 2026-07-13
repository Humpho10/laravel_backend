<div style="font-family: sans-serif; max-width: 480px; margin: 0 auto;">
    <h2 style="color: #dc2626;">Your account has been deactivated</h2>
    <p>Hi {{ $user->name }},</p>
    <p style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 16px; font-weight: bold; color: #7f1d1d;">
        Your E-Waste Mart account has been deactivated and you will not be able to log in until it is reactivated.
    </p>
    <p>If you believe this was a mistake or have questions, please get in touch with our support team.</p>
    <p style="text-align: center; margin: 28px 0;">
        <a href="{{ $supportUrl }}"
           style="background: #dc2626; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 12px; font-weight: bold; display: inline-block;">
            Contact support
        </a>
    </p>
</div>
