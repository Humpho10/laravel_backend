<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerRating extends Model
{
    protected $primaryKey = 'rating_id';

    protected $fillable = [
        'seller_id',
        'buyer_id',
        'rating',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id', 'id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id', 'id');
    }
}
