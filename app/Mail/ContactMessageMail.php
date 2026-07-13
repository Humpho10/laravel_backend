<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $topic,
        public string $messageBody,
    ) {}

    public function build()
    {
        // Reply-To is set to the visitor's own address so the site owner can
        // just hit "reply" in their inbox to respond directly to them.
        return $this->subject('New contact message: ' . $this->topic)
            ->replyTo($this->senderEmail, $this->senderName)
            ->view('emails.contact-message', [
                'senderName'  => $this->senderName,
                'senderEmail' => $this->senderEmail,
                'topic'       => $this->topic,
                'messageBody' => $this->messageBody,
            ]);
    }
}
