<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $primaryKey = 'message_id';

    protected $fillable = ['product_id', 'sender_id', 'recipient_id', 'message_text', 'is_read'];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id', 'id'); // Links to users.id
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id', 'id'); // Links to users.id
    }
}

