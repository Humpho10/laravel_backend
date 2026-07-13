<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    // Tell Eloquent your primary key name is custom
    protected $primaryKey = 'category_id'; 

    protected $fillable = ['name'];

    /**
     * Relationship to retrieve subcategories.
     * FIXED: Lowercase 'c' to match CategoryController::index() eager loading.
     */
    public function subcategories(): HasMany
    {
        return $this->hasMany(SubCategory::class, 'category_id', 'category_id');
    }

    /**
     * Relationship to retrieve products under this category.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id', 'category_id');
    }
}
