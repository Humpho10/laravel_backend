<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMessage extends Model
{
    protected $primaryKey = 'contact_message_id';

    protected $fillable = [
        'name', 'email', 'topic', 'message',
        'status', 'reply_message', 'replied_at', 'replied_by',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
    ];

    public function repliedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by', 'id');
    }
}
