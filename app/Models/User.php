<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'phone',
        'location',
        'email',
        'password',
        'email_verified_at',
        'is_active',
        'avatar'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Categories a Product-Manager has been assigned to review — empty for
    // every other role. Used to surface "which categories does this PM
    // cover?" in the Super Admin's Users list.
    public function productManagerAssignments()
    {
        return $this->hasMany(ProductManagerAssignment::class, 'product_manager_id');
    }
}
