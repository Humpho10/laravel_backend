<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $primaryKey = 'product_id';

    protected $fillable = [
    'seller_id',
    'category_id',
    'subcategory_id',
    'title',
    'description',
    'condition',
    'price',
    'specification',
    'status',
    'rejection_reason',
    'reviewed_by',
    'reviewed_at',
    'resubmitted_at',
    'slug',
    'hash_id',
];

protected $casts = [
        'reviewed_at'    => 'datetime',
        'resubmitted_at' => 'datetime',
        'price'          => 'decimal:2',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->hash_id)) {
                $product->hash_id = $product->generateHashId();
            }

            $product->slug = $product->generateUniqueSlug($product->title);
        });

        static::updating(function ($product) {
            if ($product->isDirty('title')) {
                $product->slug = $product->generateUniqueSlug($product->title);
            }
        });
    }

    /**
     * Generate unique hash ID
     */
    private function generateHashId(): string
    {
        $hash = Str::random(20);

        while (self::where('hash_id', $hash)->exists()) {
            $hash = Str::random(20);
        }

        return $hash;
    }

    /**
     * Generate unique slug from title
     */
    private function generateUniqueSlug(string $title): string
    {
        $slug = Str::slug($title);

        if (empty($slug)) {
            $slug = 'product-' . Str::random(6);
        }

        $existingSlugs = self::where('slug', 'LIKE', "{$slug}%")
            ->when($this->exists, function ($query) {
                return $query->where('product_id', '!=', $this->product_id);
            })
            ->pluck('slug')
            ->toArray();

        if (!in_array($slug, $existingSlugs)) {
            return $slug;
        }

        $counter = 1;
        $newSlug = $slug . '-' . $counter;

        while (in_array($newSlug, $existingSlugs)) {
            $counter++;
            $newSlug = $slug . '-' . $counter;
        }

        return $newSlug;
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id', 'id'); // Links to users.id
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(SubCategory::class, 'subcategory_id', 'subcategory_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id', 'product_id');
    }
}
