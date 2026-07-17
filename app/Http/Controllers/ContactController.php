<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessageMail;
use App\Models\ContactMessage;
use App\Helpers\NotificationHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Helpers\Mailer;

class ContactController extends Controller
{
    // Public endpoint (no auth) — anyone visiting the site can reach the
    // owners this way, so validation here is the only spam guard besides
    // the route's rate limiting.
    public function send(Request $request)
    {
        $data = $request->validate([
            'name'    => 'required|string|max:150',
            'email'   => 'required|email|max:150',
            'topic'   => 'nullable|string|max:100',
            'message' => 'required|string|max:5000',
        ]);

        $topic = $data['topic'] ?: 'General inquiry';

        $recipient = env('CONTACT_RECIPIENT_EMAIL', config('mail.from.address'));

        Mailer::send($recipient, new ContactMessageMail(
            $data['name'],
            $data['email'],
            $topic,
            $data['message'],
        ));

        $contactMessage = ContactMessage::create([
            'name'    => $data['name'],
            'email'   => $data['email'],
            'topic'   => $topic,
            'message' => $data['message'],
            'status'  => 'new',
        ]);

        NotificationHelper::notifyAdmins(
            'contact_message',
            $contactMessage->contact_message_id,
            "New contact message from {$data['name']}: {$topic}",
        );

        return response()->json([
            'message' => 'Your message has been sent. We\'ll get back to you soon.',
        ]);
    }
}
