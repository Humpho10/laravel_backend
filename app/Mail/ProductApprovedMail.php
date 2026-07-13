<?php

namespace App\Mail;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProductApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Product $product) {}

    public function build()
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

        return $this->subject('Your listing "' . $this->product->title . '" has been approved')
            ->view('emails.product-approved', [
                'product'    => $this->product,
                'listingUrl' => $frontendUrl . '/dashboard/listings',
            ]);
    }
}
