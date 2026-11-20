<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Fase 121: el ticket de acceso de ARCA (WSAA) de cada certificado y servicio. Token y sign van cifrados.
class ArcaTicket extends Model
{
    protected $fillable = ['huella', 'servicio', 'produccion', 'token', 'sign', 'expira'];
    protected $casts = ['produccion' => 'boolean', 'expira' => 'datetime'];
    protected $hidden = ['token', 'sign'];
}
