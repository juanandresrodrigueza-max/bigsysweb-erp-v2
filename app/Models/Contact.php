<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use SoftDeletes, BelongsToBusiness;

    protected $fillable = ['business_id', 'type', 'name', 'email', 'phone', 'mobile', 'document_type', 'document', 'cuit', 'condicion_iva', 'address', 'city', 'province', 'postal_code', 'credit_limit', 'balance', 'is_active', 'notes'];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'balance'      => 'decimal:2',
        'is_active'    => 'boolean',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function scopeCustomers($query)
    {
        return $query->whereIn('type', ['customer', 'both']);
    }

    public function scopeSuppliers($query)
    {
        return $query->whereIn('type', ['supplier', 'both']);
    }
}
