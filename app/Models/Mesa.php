<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Mesa extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'business_location_id', 'nombre', 'sector', 'capacidad', 'orden', 'activa', 'pos_x', 'pos_y', 'forma', 'token'];
    protected $casts = ['activa' => 'boolean'];

    // Fase 95: el QR de la mesa lleva un token, no el id: desde afuera nadie adivina qué mesa es ni le carga pedidos.
    protected static function booted(): void
    {
        static::creating(function (Mesa $m) { $m->token = $m->token ?: \Illuminate\Support\Str::random(24); });
    }

    public function tokenQr(): string
    {
        if (! $this->token) { $this->token = \Illuminate\Support\Str::random(24); $this->saveQuietly(); }
        return $this->token;
    }

    public function urlQr(string $slug): string { return url('/m/' . $slug . '?mesa=' . $this->tokenQr()); }

    public function comandaAbierta(): HasOne
    {
        return $this->hasOne(Comanda::class)->whereIn('estado', ['abierta', 'cuenta'])->latestOfMany();
    }
}
