<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class PuntoVenta extends Model
{
    use BelongsToBusiness;

    protected $table = 'puntos_venta';
    protected $fillable = ['business_id', 'business_location_id', 'numero', 'modo', 'activo'];
    protected $casts = ['activo' => 'boolean'];

    public function location(): BelongsTo { return $this->belongsTo(BusinessLocation::class, 'business_location_id'); }

    public function proximoNumero(string $tipo): int
    {
        return DB::transaction(function () use ($tipo) {
            $row = DB::table('punto_venta_numeros')->where('punto_venta_id', $this->id)->where('tipo', $tipo)->lockForUpdate()->first();
            if (! $row) {
                DB::table('punto_venta_numeros')->insert(['punto_venta_id' => $this->id, 'tipo' => $tipo, 'ultimo' => 1]);
                return 1;
            }
            DB::table('punto_venta_numeros')->where('id', $row->id)->update(['ultimo' => $row->ultimo + 1]);
            return $row->ultimo + 1;
        });
    }

    public function sincronizarUltimo(string $tipo, int $ultimo): void
    {
        DB::table('punto_venta_numeros')->updateOrInsert(['punto_venta_id' => $this->id, 'tipo' => $tipo], ['ultimo' => $ultimo]);
    }
}
