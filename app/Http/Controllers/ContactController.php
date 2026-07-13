<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessageMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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

        $recipient = env('CONTACT_RECIPIENT_EMAIL', config('mail.from.address'));

        Mail::to($recipient)->send(new ContactMessageMail(
            $data['name'],
            $data['email'],
            $data['topic'] ?: 'General inquiry',
            $data['message'],
        ));

        return response()->json([
            'message' => 'Your message has been sent. We\'ll get back to you soon.',
        ]);
    }
}
