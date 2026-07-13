<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $verifyUrl;

    public function __construct(public string $name, string $token)
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $this->verifyUrl = $frontendUrl . '/verify-email?token=' . urlencode($token);
    }

    public function build()
    {
        return $this->subject('Verify your E-Waste Mart account')
            ->view('emails.verify-email');
    }
}
