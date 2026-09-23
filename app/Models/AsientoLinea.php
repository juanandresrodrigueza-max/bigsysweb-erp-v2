<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsientoLinea extends Model
{
    public $timestamps = false;
    protected $fillable = ['asiento_id', 'cuenta_id', 'contact_id', 'debe', 'haber', 'detalle'];
    protected $casts = ['debe' => 'decimal:2', 'haber' => 'decimal:2'];

    public function asiento(): BelongsTo { return $this->belongsTo(Asiento::class); }
    public function cuenta(): BelongsTo { return $this->belongsTo(CuentaContable::class, 'cuenta_id'); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
}
