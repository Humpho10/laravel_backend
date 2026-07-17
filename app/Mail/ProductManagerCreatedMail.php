<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProductManagerCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
    ) {}

    public function build()
    {
        $loginUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/') . '/login';

        return $this->subject('Your Product Manager Account Has Been Created')
            ->view('emails.pm_created', [
                'name'     => $this->name,
                'email'    => $this->email,
                'loginUrl' => $loginUrl,
            ]);
    }
}
