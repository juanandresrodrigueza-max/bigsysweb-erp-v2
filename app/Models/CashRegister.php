<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashRegister extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'business_location_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class);
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(CashRegisterSession::class)->where('status', 'open')->latestOfMany();
    }
}
