<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BusinessLocation extends Model
{
    protected $fillable = ['business_id', 'name', 'short_name', 'address', 'city', 'province', 'postal_code', 'phone', 'email', 'is_active', 'is_default'];

    protected $casts = ['is_active' => 'boolean', 'is_default' => 'boolean'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_locations')->withPivot('role_id')->withTimestamps();
    }
}
