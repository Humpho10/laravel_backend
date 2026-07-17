<?php

namespace App\Http\Controllers;

use App\Helpers\AuditLogger;
use App\Helpers\Mailer;
use App\Mail\ContactReplyMail;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    // GET /manager/contact-messages?status=new&search=&per_page=10
    public function index(Request $request)
    {
        $query = ContactMessage::query();

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('message', 'like', "%{$search}%");
            });
        }

        $counts = [
            'new'     => (clone $query)->where('status', 'new')->count(),
            'read'    => (clone $query)->where('status', 'read')->count(),
            'replied' => (clone $query)->where('status', 'replied')->count(),
        ];

        $perPage = min(max((int) $request->get('per_page', 15), 5), 100);
        $paginated = $query->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'messages' => $paginated,
            'counts'   => $counts,
        ]);
    }

    // GET /manager/contact-messages/{id} — also marks 'new' -> 'read'
    public function show($id)
    {
        $contactMessage = ContactMessage::findOrFail($id);

        if ($contactMessage->status === 'new') {
            $contactMessage->update(['status' => 'read']);
        }

        return response()->json(['message' => $contactMessage]);
    }

    // POST /manager/contact-messages/{id}/reply { reply_message }
    public function reply(Request $request, $id)
    {
        $data = $request->validate([
            'reply_message' => 'required|string|max:5000',
        ]);

        $contactMessage = ContactMessage::findOrFail($id);
        $oldValue = $contactMessage->toArray();

        Mailer::send($contactMessage->email, new ContactReplyMail(
            $contactMessage->name,
            $contactMessage->topic,
            $contactMessage->message,
            $data['reply_message'],
        ));

        $contactMessage->update([
            'reply_message' => $data['reply_message'],
            'replied_at'    => now(),
            'replied_by'    => $request->user()->id,
            'status'        => 'replied',
        ]);

        AuditLogger::log('contact_messages', $contactMessage->contact_message_id, 'replied', $oldValue, $contactMessage->toArray());

        return response()->json(['message' => $contactMessage->fresh()]);
    }
}
