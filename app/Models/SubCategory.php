<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubCategory extends Model
{
    // Explicitly link to your snake_case database table name
    protected $table = 'sub_categories'; 
    
    // Tell Eloquent your primary key name is custom
    protected $primaryKey = 'subcategory_id';

    protected $fillable = ['category_id', 'sub_category_name'];

    /**
     * Relationship linking back to the parent Category.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    /**
     * Relationship to retrieve products under this specific subcategory.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'subcategory_id', 'subcategory_id');
    }
}
