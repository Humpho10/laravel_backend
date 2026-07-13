<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $table = 'product_images';
    protected $primaryKey = 'productImage_id';

    protected $fillable = ['product_id', 'image_path'];

    // 🔥 This tells Laravel to include image_url in JSON responses
    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): string
    {
        if (empty($this->image_path)) {
            return asset('images/placeholder.png');
        }
        return asset('storage/' . $this->image_path);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}