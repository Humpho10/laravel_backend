<div style="font-family: sans-serif; max-width: 480px; margin: 0 auto;">
    <h2 style="color: #dc2626;">Your listing was rejected</h2>
    <p>Your listing below was reviewed and could not be approved:</p>
    <p style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 16px; font-weight: bold; color: #7f1d1d;">
        {{ $product->title }}
    </p>
    @if ($product->rejection_reason)
        <p style="color: #6b7280; font-size: 14px;"><strong>Reason:</strong> {{ $product->rejection_reason }}</p>
    @endif
    <p>You can update your listing and resubmit it for review.</p>
    <p style="text-align: center; margin: 28px 0;">
        <a href="{{ $listingUrl }}"
           style="background: #dc2626; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 12px; font-weight: bold; display: inline-block;">
            View my listings
        </a>
    </p>
</div>
