<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductManagerAssignment extends Model
{
    protected $fillable = [
        'product_manager_id',
        'category_id',
    ];

    public function productManager()
    {
        return $this->belongsTo(User::class, 'product_manager_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }
}
