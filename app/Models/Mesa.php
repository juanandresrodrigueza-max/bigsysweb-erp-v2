<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Mesa extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'business_location_id', 'nombre', 'sector', 'capacidad', 'orden', 'activa'];
    protected $casts = ['activa' => 'boolean'];

    public function comandaAbierta(): HasOne
    {
        return $this->hasOne(Comanda::class)->whereIn('estado', ['abierta', 'cuenta'])->latestOfMany();
    }
}
