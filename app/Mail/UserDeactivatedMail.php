<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserDeactivatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function build()
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

        return $this->subject('Your E-Waste Mart account has been deactivated')
            ->view('emails.user-deactivated', [
                'user'        => $this->user,
                'supportUrl'  => $frontendUrl . '/contact',
            ]);
    }
}
