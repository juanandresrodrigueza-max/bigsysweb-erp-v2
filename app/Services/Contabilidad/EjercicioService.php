<?php

namespace App\Services\Contabilidad;

use App\Models\Asiento;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\CuentaContable;
use App\Models\Ejercicio;
use App\Models\IndiceIpc;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

// Cierre de ejercicio (refundición, cierre y apertura), ajuste por inflación con IPC y libro diario.
class EjercicioService
{
    public function __construct(private ContabilidadService $contabilidad) {}

    public function actual(Business $b): array
    {
        $mes = (int) ($b->cierre_ejercicio_mes ?: 12);
        $ultimo = Ejercicio::where('estado', 'cerrado')->orderByDesc('hasta')->first();
        $desde = $ultimo ? $ultimo->hasta->copy()->addDay() : (today()->month > $mes ? \Carbon\Carbon::create(today()->year, $mes, 1)->addMonth()->startOfMonth() : \Carbon\Carbon::create(today()->year - 1, $mes, 1)->addMonth()->startOfMonth());
        $hasta = $desde->copy()->addYear()->subDay();
        return ['desde' => $desde, 'hasta' => $hasta, 'ultimo' => $ultimo];
    }

    // Cierra el ejercicio que termina en $hasta: refundición de resultados, cierre patrimonial y apertura al día siguiente.
    public function cerrar(Business $b, string $hasta): Ejercicio
    {
        return DB::transaction(function () use ($b, $hasta) {
            $hasta = \Carbon\Carbon::parse($hasta);
            $act = $this->actual($b);
            if (Ejercicio::where('estado', 'cerrado')->where('hasta', '>=', $hasta)->exists()) throw ValidationException::withMessages(['hasta' => 'Ya hay un ejercicio cerrado a esa fecha o posterior.']);
            $desde = $act['desde'];
            $sumas = $this->contabilidad->sumas($b->id, $desde->toDateString(), $hasta->toDateString());
            $cuentas = CuentaContable::where('imputable', true)->get()->keyBy('id');
            $user = Auth::user();
            $nro = fn() => (Asiento::withoutGlobalScopes()->where('business_id', $b->id)->max('numero') ?? 0) + 1;

            // 1) Refundición: cuentas de resultado contra Resultados acumulados
            $ref = Asiento::create(['business_id' => $b->id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'numero' => $nro(), 'fecha' => $hasta, 'concepto' => 'Refundición de cuentas de resultado · cierre ' . $hasta->format('d/m/Y'), 'origen' => 'cierre', 'total' => 0]);
            $resultado = 0; $tot = 0;
            foreach ($sumas as $id => $s) {
                $c = $cuentas[$id] ?? null; if (! $c || ! in_array($c->tipo, ['ingreso', 'egreso'], true)) continue;
                $saldo = round($s['debe'] - $s['haber'], 2); if (abs($saldo) < 0.005) continue;
                $ref->lineas()->create(['cuenta_id' => $id, 'debe' => $saldo < 0 ? -$saldo : 0, 'haber' => $saldo > 0 ? $saldo : 0, 'detalle' => 'Refundición']);
                $resultado -= $saldo; $tot += abs($saldo);
            }
            $resAcum = CuentaContable::where('clave', 'resultados')->first();
            if (abs($resultado) > 0.005) $ref->lineas()->create(['cuenta_id' => $resAcum->id, 'debe' => $resultado < 0 ? -$resultado : 0, 'haber' => $resultado > 0 ? $resultado : 0, 'detalle' => 'Resultado del ejercicio']);
            $ref->update(['total' => round($tot, 2)]);

            // 2) Cierre patrimonial: todas las cuentas de activo, pasivo y PN a cero (saldos acumulados desde siempre)
            $sumasTotal = $this->contabilidad->sumas($b->id, null, $hasta->toDateString());
            $cie = Asiento::create(['business_id' => $b->id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'numero' => $nro(), 'fecha' => $hasta, 'concepto' => 'Asiento de cierre · ' . $hasta->format('d/m/Y'), 'origen' => 'cierre', 'total' => 0]);
            $ape = Asiento::create(['business_id' => $b->id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'numero' => $nro(), 'fecha' => $hasta->copy()->addDay(), 'concepto' => 'Asiento de apertura · ' . $hasta->copy()->addDay()->format('d/m/Y'), 'origen' => 'apertura', 'total' => 0]);
            $tc = 0;
            foreach ($sumasTotal as $id => $s) {
                $c = $cuentas[$id] ?? null; if (! $c || in_array($c->tipo, ['ingreso', 'egreso'], true)) continue;
                $saldo = round($s['debe'] - $s['haber'], 2);
                if ($id === $resAcum->id) $saldo = round($saldo - $resultado, 2); // ya incluye la refundición recién hecha
                if (abs($saldo) < 0.005) continue;
                $cie->lineas()->create(['cuenta_id' => $id, 'debe' => $saldo < 0 ? -$saldo : 0, 'haber' => $saldo > 0 ? $saldo : 0, 'detalle' => 'Cierre']);
                $ape->lineas()->create(['cuenta_id' => $id, 'debe' => $saldo > 0 ? $saldo : 0, 'haber' => $saldo < 0 ? -$saldo : 0, 'detalle' => 'Apertura']);
                $tc += abs($saldo);
            }
            $cie->update(['total' => round($tc, 2)]); $ape->update(['total' => round($tc, 2)]);

            $e = Ejercicio::create(['business_id' => $b->id, 'desde' => $desde, 'hasta' => $hasta, 'estado' => 'cerrado', 'cerrado_en' => now(), 'asientos' => ['refundicion' => $ref->id, 'cierre' => $cie->id, 'apertura' => $ape->id, 'resultado' => round($resultado, 2)]]);
            AuditLog::registrar('crear', $e, 'Cierre de ejercicio al ' . $hasta->format('d/m/Y') . ': resultado $ ' . number_format($resultado, 2, ',', '.'));
            return $e;
        });
    }

    // Reabre el último ejercicio cerrado: anula sus asientos de cierre.
    public function reabrir(Ejercicio $e): void
    {
        DB::transaction(function () use ($e) {
            foreach (['refundicion', 'cierre', 'apertura', 'ajuste'] as $k) if ($id = $e->asientos[$k] ?? null) Asiento::where('id', $id)->update(['estado' => 'anulado']);
            $e->update(['estado' => 'abierto']);
            AuditLog::registrar('anular', $e, 'Reabrió el ejercicio cerrado al ' . $e->hasta->format('d/m/Y'));
        });
    }

    // Ajuste por inflación simplificado: cada cuenta ajustable se reexpresa por el IPC del mes de cada movimiento al mes de cierre; la contrapartida es RECPAM.
    public function ajustePorInflacion(Business $b, string $desde, string $hasta): Asiento
    {
        return DB::transaction(function () use ($b, $desde, $hasta) {
            $cierre = \Carbon\Carbon::parse($hasta)->format('Y-m');
            if (! IndiceIpc::valor($cierre)) throw ValidationException::withMessages(['hasta' => "Falta el IPC de {$cierre}. Cargalo o actualizalo en Configuración → Impuestos."]);
            $cuentas = CuentaContable::where('ajustable', true)->where('imputable', true)->get();
            $movs = DB::table('asiento_lineas')->join('asientos', 'asientos.id', '=', 'asiento_lineas.asiento_id')->where('asientos.business_id', $b->id)->where('asientos.estado', 'confirmado')->whereIn('asiento_lineas.cuenta_id', $cuentas->pluck('id'))
                ->where('asientos.fecha', '<=', $hasta)->where('asientos.origen', '!=', 'ajuste_inflacion')->selectRaw("asiento_lineas.cuenta_id, " . \App\Support\Sql::mes('asientos.fecha') . " as periodo, SUM(debe - haber) as saldo")->groupBy('asiento_lineas.cuenta_id', 'periodo')->get();
            $user = Auth::user();
            $a = Asiento::create(['business_id' => $b->id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'numero' => (Asiento::withoutGlobalScopes()->where('business_id', $b->id)->max('numero') ?? 0) + 1, 'fecha' => $hasta, 'concepto' => "Ajuste por inflación (IPC) al " . \Carbon\Carbon::parse($hasta)->format('d/m/Y'), 'origen' => 'ajuste_inflacion', 'total' => 0]);
            $recpam = 0; $tot = 0; $porCuenta = []; $sinIpc = [];
            foreach ($movs as $m) {
                $coef = IndiceIpc::coeficiente($m->periodo, $cierre);
                if (! $coef) { $sinIpc[$m->periodo] = true; continue; }
                $porCuenta[$m->cuenta_id] = ($porCuenta[$m->cuenta_id] ?? 0) + (float) $m->saldo * ($coef - 1);
            }
            foreach ($porCuenta as $id => $aj) {
                $aj = round($aj, 2); if (abs($aj) < 0.005) continue;
                $a->lineas()->create(['cuenta_id' => $id, 'debe' => $aj > 0 ? $aj : 0, 'haber' => $aj < 0 ? -$aj : 0, 'detalle' => 'Reexpresión por IPC']);
                $recpam += $aj; $tot += abs($aj);
            }
            $cta = CuentaContable::where('clave', 'recpam')->first();
            if (abs($recpam) > 0.005) $a->lineas()->create(['cuenta_id' => $cta->id, 'debe' => $recpam < 0 ? -$recpam : 0, 'haber' => $recpam > 0 ? $recpam : 0, 'detalle' => 'RECPAM']);
            $a->update(['total' => round($tot, 2)]);
            AuditLog::registrar('crear', $a, "Ajuste por inflación al {$hasta}: RECPAM $ " . number_format($recpam, 2, ',', '.') . ($sinIpc ? ' (sin IPC para ' . implode(', ', array_keys($sinIpc)) . ')' : ''));
            return $a;
        });
    }

    // IPC nivel general nacional (INDEC) desde datos.gob.ar; si no hay red, queda lo cargado a mano.
    public function actualizarIpc(): array
    {
        try {
            $r = Http::timeout(10)->get('https://apis.datos.gob.ar/series/api/series/', ['ids' => '148.3_INIVELNAL_DICI_M_26', 'format' => 'json', 'limit' => 120, 'sort' => 'desc']);
            if (! $r->ok()) return ['error' => 'HTTP ' . $r->status()];
            $n = 0;
            foreach ($r->json('data') ?? [] as [$fecha, $valor]) { if ($valor === null) continue; IndiceIpc::updateOrCreate(['periodo' => substr($fecha, 0, 7)], ['valor' => $valor, 'fuente' => 'INDEC']); $n++; }
            return ['actualizados' => $n];
        } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
    }
}
