<?php

namespace App\Services\Empresas;

use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Liquidacion;
use Illuminate\Support\Facades\DB;

// Fase 39.3: el estudio contable atiende a sus clientes desde el mismo sistema. Su marca, el precio progresivo por empresa administrada,
// el semáforo de qué falta en cada una y los vencimientos por terminación de CUIT.
class EstudioService
{
    // Día de vencimiento de la DDJJ de IVA mensual por terminación de CUIT. ARCA lo cambia cada año: se corrige en la pantalla.
    public const VENCIMIENTOS = ['0' => 18, '1' => 18, '2' => 19, '3' => 19, '4' => 20, '5' => 20, '6' => 21, '7' => 21, '8' => 22, '9' => 22];
    public const TRAMOS = [['hasta' => 5, 'precio' => 15000], ['hasta' => 20, 'precio' => 11000], ['hasta' => null, 'precio' => 8000]];

    public function config(Business $estudio): array
    {
        $e = $estudio->estudio ?? [];
        return ['es_estudio' => (bool) ($e['es_estudio'] ?? false), 'marca' => $e['marca'] ?? $estudio->name, 'tramos' => $e['tramos'] ?? self::TRAMOS, 'vencimientos' => $e['vencimientos'] ?? self::VENCIMIENTOS];
    }

    public function guardar(Business $estudio, array $d): void
    {
        $tramos = collect($d['tramos'] ?? self::TRAMOS)->map(fn($t) => ['hasta' => isset($t['hasta']) && $t['hasta'] !== '' && $t['hasta'] !== null ? (int) $t['hasta'] : null, 'precio' => (float) ($t['precio'] ?? 0)])->values()->all();
        $estudio->estudio = ['es_estudio' => (bool) ($d['es_estudio'] ?? true), 'marca' => $d['marca'] ?? $estudio->name, 'tramos' => $tramos, 'vencimientos' => array_map('intval', $d['vencimientos'] ?? self::VENCIMIENTOS)];
        $estudio->save();
    }

    // Precio progresivo: las primeras empresas pagan el precio del primer tramo, las siguientes el del segundo, y así.
    public function precio(int $n, array $tramos): array
    {
        $total = 0; $desde = 0; $detalle = [];
        foreach ($tramos as $t) {
            if ($n <= $desde) break;
            $hasta = $t['hasta'] === null ? $n : min($n, (int) $t['hasta']);
            $cant = max(0, $hasta - $desde);
            if ($cant) { $total += $cant * (float) $t['precio']; $detalle[] = ['desde' => $desde + 1, 'hasta' => $hasta, 'cantidad' => $cant, 'precio' => (float) $t['precio']]; }
            $desde = $hasta;
        }
        return ['empresas' => $n, 'total' => round($total, 2), 'promedio' => $n ? round($total / $n, 2) : 0, 'detalle' => $detalle];
    }

    // El semáforo de cada empresa en el mes: verde al día, amarillo algo para mirar, rojo algo trabado.
    public function semaforo(Business $b, string $mes): array
    {
        [$desde, $hasta] = [\Carbon\Carbon::parse($mes . '-01')->toDateString(), \Carbon\Carbon::parse($mes . '-01')->endOfMonth()->toDateString()];
        $falta = [];
        $cae = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('afip_estado', 'pendiente')->count();
        if ($cae) $falta[] = ['nivel' => 'rojo', 'texto' => "{$cae} comprobantes sin CAE"];
        $ventas = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->whereBetween('fecha', [$desde, $hasta])->count();
        $compras = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'compra')->where('estado', 'emitido')->whereBetween('fecha', [$desde, $hasta])->count();
        if (! $ventas) $falta[] = ['nivel' => 'amarillo', 'texto' => 'libro IVA ventas vacío'];
        if (! $compras) $falta[] = ['nivel' => 'amarillo', 'texto' => 'libro IVA compras vacío (¿faltan cargar compras?)'];
        $borr = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('estado', 'borrador')->where('created_at', '<', now()->subDays(3))->count();
        if ($borr) $falta[] = ['nivel' => 'amarillo', 'texto' => "{$borr} comprobantes esperando en borrador"];
        $emp = DB::table('empleados')->where('business_id', $b->id)->where('activo', true)->count();
        if ($emp) {
            $liq = Liquidacion::withoutGlobalScopes()->where('business_id', $b->id)->where('periodo', $mes)->where('tipo', 'mensual')->first();
            if (! $liq) $falta[] = ['nivel' => 'rojo', 'texto' => "sueldos de {$mes} sin liquidar ({$emp} empleados)"];
            elseif ($liq->estado === 'borrador') $falta[] = ['nivel' => 'amarillo', 'texto' => 'sueldos liquidados pero sin confirmar'];
        }
        $sinAsiento = DB::table('comprobantes')->where('business_id', $b->id)->where('estado', 'emitido')->whereBetween('fecha', [$desde, $hasta])
            ->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('asientos')->whereColumn('asientos.origen_id', 'comprobantes.id')->whereColumn('asientos.origen', 'comprobantes.direccion')->where('asientos.estado', 'confirmado'))->count();
        if ($sinAsiento) $falta[] = ['nivel' => 'amarillo', 'texto' => "{$sinAsiento} comprobantes sin asiento"];
        $nivel = collect($falta)->contains('nivel', 'rojo') ? 'rojo' : ($falta ? 'amarillo' : 'verde');
        return ['nivel' => $nivel, 'falta' => $falta];
    }

    // Los vencimientos del mes siguiente al período, por la terminación del CUIT de cada empresa.
    public function vencimientos(\Illuminate\Support\Collection $empresas, string $mes, array $tabla): array
    {
        $venc = \Carbon\Carbon::parse($mes . '-01')->addMonth();
        return $empresas->map(function (Business $b) use ($venc, $tabla) {
            $d = substr(preg_replace('/\D/', '', (string) $b->cuit), -1);
            if ($d === '' || $d === false) return null;
            $f = $venc->copy()->day(min((int) ($tabla[$d] ?? 20), $venc->daysInMonth));
            while ($f->isWeekend()) $f->addDay(); // si cae fin de semana, pasa al día hábil siguiente
            return ['empresa' => $b->name, 'id' => $b->id, 'terminacion' => $d, 'fecha' => $f->toDateString(), 'dias' => (int) today()->diffInDays($f, false), 'que' => 'IVA mensual'];
        })->filter()->sortBy('fecha')->values()->all();
    }
}
