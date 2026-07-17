<?php

namespace App\Helpers;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends transactional mail without ever letting a mail-server hiccup break
 * the operation that triggered it.
 *
 * Registration, approval, rejection, etc. must succeed even if the SMTP
 * server is down, rate-limiting ("550 sending too fast"), or misconfigured —
 * the email is a side effect, not the point of the request. Failures are
 * logged so they're still visible in the logs, but never thrown.
 *
 * Returns true if the mail was handed off successfully, false otherwise.
 */
class Mailer
{
    public static function send(?string $recipient, Mailable $mailable): bool
    {
        if (!$recipient) {
            return false;
        }

        try {
            Mail::to($recipient)->send($mailable);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Mail send failed (non-fatal)', [
                'recipient' => $recipient,
                'mailable'  => get_class($mailable),
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }
}
