<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 121.1: la constancia de que una empresa (o una sucursal con CUIT propio) autorizó a BigSys a emitir en su nombre.
class ArcaAutorizacion extends Model
{
    use BelongsToBusiness;

    protected $table = 'arca_autorizaciones';
    protected $fillable = ['business_id', 'sucursal_id', 'user_id', 'usuario', 'cuit', 'razon_social', 'representante', 'version', 'texto', 'ip', 'navegador', 'aceptada_en', 'revocada_en', 'revocada_por', 'motivo_revocacion'];
    protected $casts = ['aceptada_en' => 'datetime', 'revocada_en' => 'datetime'];
}
