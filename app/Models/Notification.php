<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $primaryKey = 'notification_id';

    protected $fillable = [
    'user_id',
    'type',
    'reference_id',
    'message',
    'is_read',
];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id'); // Links to users.id
    }
}

