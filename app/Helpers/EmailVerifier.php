<?php

namespace App\Helpers;

use App\Mail\VerifyEmailMail;
use App\Models\EmailVerification;
use App\Models\User;
use Illuminate\Support\Str;

class EmailVerifier
{
    public static function send(User $user): void
    {
        EmailVerification::where('email', $user->email)->delete();

        $token = Str::random(64);

        EmailVerification::create([
            'email'      => $user->email,
            'token'      => $token,
            'expires_at' => now()->addHours(24),
        ]);

        Mailer::send($user->email, new VerifyEmailMail($user->name, $token));
    }
}
