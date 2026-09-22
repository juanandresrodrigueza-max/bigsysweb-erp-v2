<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = ['business_id', 'business_location_id', 'name', 'email', 'phone', 'document', 'address', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
