<div style="font-family: sans-serif; max-width: 480px; margin: 0 auto;">
    <h2 style="color: #0b2545;">Reply to your message</h2>
    <p>Hi {{ $recipientName }},</p>
    <p style="white-space: pre-wrap; line-height: 1.6; color: #1f2937;">{{ $replyMessage }}</p>

    <p style="margin-top: 24px; font-size: 13px; color: #64748b;">In response to your message:</p>
    <blockquote style="background: #f8fafc; border-left: 3px solid #cbd5e1; margin: 8px 0; padding: 12px 16px; color: #475569; font-size: 13px; white-space: pre-wrap;">
        <strong>Topic: {{ $originalTopic }}</strong><br>
        {{ $originalMessage }}
    </blockquote>

    <p style="margin-top: 28px; font-size: 12px; color: #9ca3af;">— E-Waste Mart Team</p>
</div>
