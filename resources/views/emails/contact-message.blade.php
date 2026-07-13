<div style="font-family: sans-serif; max-width: 480px; margin: 0 auto;">
    <h2 style="color: #0b2545;">New message from the Contact page</h2>
    <p style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 16px; color: #1e3a5f;">
        <strong>{{ $senderName }}</strong><br>
        <a href="mailto:{{ $senderEmail }}" style="color: #2563eb;">{{ $senderEmail }}</a><br>
        <span style="display:inline-block; margin-top:8px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.03em; color:#64748b;">Topic: {{ $topic }}</span>
    </p>
    <p style="white-space: pre-wrap; line-height: 1.6; color: #1f2937;">{{ $messageBody }}</p>
    <p style="margin-top: 28px; font-size: 12px; color: #9ca3af;">
        Reply directly to this email to respond to {{ $senderName }}.
    </p>
</div>
