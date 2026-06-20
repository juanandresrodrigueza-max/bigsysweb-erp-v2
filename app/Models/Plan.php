<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'price_monthly', 'price_yearly', 'max_users', 'max_locations', 'max_products', 'features', 'is_active', 'is_free'];

    protected $casts = [
        'features'      => 'array',
        'is_active'     => 'boolean',
        'is_free'       => 'boolean',
        'price_monthly' => 'decimal:2',
        'price_yearly'  => 'decimal:2',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }
}
