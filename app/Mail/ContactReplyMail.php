<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $originalTopic,
        public string $originalMessage,
        public string $replyMessage,
    ) {}

    public function build()
    {
        // Reply-To points back at the shared contact inbox rather than the
        // admin's personal address, since replies aren't ingested back into
        // the app.
        $replyToAddress = env('CONTACT_RECIPIENT_EMAIL', config('mail.from.address'));

        return $this->subject('Re: ' . $this->originalTopic)
            ->replyTo($replyToAddress)
            ->view('emails.contact-reply', [
                'recipientName'   => $this->recipientName,
                'originalTopic'   => $this->originalTopic,
                'originalMessage' => $this->originalMessage,
                'replyMessage'    => $this->replyMessage,
            ]);
    }
}
