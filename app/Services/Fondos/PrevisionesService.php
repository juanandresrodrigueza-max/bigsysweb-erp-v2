<?php

namespace App\Services\Fondos;

use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\CuentaFondos;
use App\Models\MovimientoFondos;
use App\Models\Prevision;
use Illuminate\Support\Facades\DB;

// Previsiones recurrentes: qué vence, registrarlas en fondos (a mano o solas) y avisar antes.
class PrevisionesService
{
    public function __construct(private FondosService $fondos) {}

    public function guardar(array $d, ?Prevision $p = null): Prevision
    {
        $p ??= new Prevision(['business_id' => auth()->user()->business_id]);
        $p->fill($d);
        $p->proximo = $p->calcularProximo(max(today(), $p->desde));
        $p->save();
        return $p;
    }

    // Registra el vencimiento en la cuenta de fondos (gasto o ingreso) y corre al próximo.
    public function registrar(Prevision $p, ?int $cuentaId = null, ?string $fecha = null, ?float $monto = null): MovimientoFondos
    {
        return DB::transaction(function () use ($p, $cuentaId, $fecha, $monto) {
            $cuenta = CuentaFondos::withoutGlobalScopes()->find($cuentaId ?: $p->cuenta_fondos_id)
                ?? CuentaFondos::withoutGlobalScopes()->where('business_id', $p->business_id)->where('activa', true)->where('tipo', 'caja')->orderByDesc('es_default')->first();
            abort_if(! $cuenta, 422, 'No hay una cuenta de fondos activa para registrar la previsión.');
            $vence = $p->proximo ?? $p->calcularProximo();
            $m = $this->fondos->registrar($cuenta, [
                'fecha' => $fecha ?: ($vence ?? today())->toDateString(), 'origen' => $p->tipo === 'ingreso' ? 'ingreso' : 'gasto', 'expense_category_id' => $p->tipo === 'egreso' ? $p->expense_category_id : null,
                'concepto' => $p->descripcion . ' (previsión ' . ($vence ?? today())->format('m/Y') . ')', $p->tipo === 'ingreso' ? 'ingreso' : 'egreso' => $monto ?? (float) $p->monto,
            ]);
            $m->forceFill(['prevision_id' => $p->id])->save();
            $p->forceFill(['ultimo_registrado_en' => now(), 'proximo' => $vence ? $p->calcularProximo($vence->copy()->addDay()) : null])->save();
            Alerta::withoutGlobalScopes()->where('business_id', $p->business_id)->where('tipo', 'prevision')->where('modelo_id', $p->id)->whereNull('resuelta_en')->update(['resuelta_en' => now()]);
            AuditLog::registrar('crear', $m, "Registró la previsión {$p->descripcion} por $ " . number_format((float) $m->egreso + (float) $m->ingreso, 2, ',', '.') . " en {$cuenta->nombre}");
            return $m;
        });
    }

    // Corre todos los días: registra solas las automáticas vencidas y avisa las que vencen pronto. Devuelve [registradas, avisadas].
    public function procesar(): array
    {
        $registradas = 0; $avisadas = 0;
        foreach (Prevision::withoutGlobalScopes()->where('activo', true)->whereNotNull('proximo')->get() as $p) {
            if ($p->registrar_auto && $p->proximo->lte(today())) { $this->registrar($p); $registradas++; continue; }
            $dias = today()->diffInDays($p->proximo, false);
            if ($dias <= (int) $p->avisar_dias) {
                Alerta::withoutGlobalScopes()->updateOrCreate(
                    ['business_id' => $p->business_id, 'tipo' => 'prevision', 'modelo' => 'Prevision', 'modelo_id' => $p->id],
                    ['business_location_id' => $p->business_location_id, 'modulo' => 'fondos', 'severidad' => $dias < 0 ? 'critica' : 'aviso',
                        'titulo' => ($p->tipo === 'ingreso' ? 'Ingreso previsto: ' : 'Vence: ') . $p->descripcion . ' $ ' . number_format((float) $p->monto, 0, ',', '.'),
                        'detalle' => ($dias < 0 ? 'Venció hace ' . abs($dias) . ' día(s)' : ($dias === 0 ? 'Vence hoy' : "Vence en {$dias} día(s)")) . ' (' . $p->proximo->format('d/m/Y') . '). ' . $p->frecuenciaLabel() . '.',
                        'url' => '/fondos/previsiones', 'resuelta_en' => null]
                );
                $avisadas++;
            }
        }
        $avisadas += $this->recordarAbonos();
        return [$registradas, $avisadas];
    }

    // Recordatorios para facturar lo recurrente: abonos que vencen en los próximos días y facturas de abonos que quedaron en borrador sin emitir.
    public function recordarAbonos(int $diasAntes = 3): int
    {
        $n = 0; $vivos = [];
        foreach (\App\Models\Abono::withoutGlobalScopes()->with('contact:id,name')->where('activo', true)->whereNotNull('proximo')->whereDate('proximo', '<=', today()->addDays($diasAntes))->get() as $a) {
            $dias = (int) today()->diffInDays($a->proximo, false);
            Alerta::withoutGlobalScopes()->updateOrCreate(['business_id' => $a->business_id, 'tipo' => 'abono_por_facturar', 'modelo' => 'Abono', 'modelo_id' => $a->id],
                ['modulo' => 'comprobantes', 'severidad' => $dias <= 0 ? 'critica' : 'aviso', 'titulo' => 'Facturar: ' . $a->descripcion . ' · ' . ($a->contact?->name ?? ''),
                    'detalle' => ($dias < 0 ? 'Venció hace ' . abs($dias) . ' día(s)' : ($dias === 0 ? 'Vence hoy' : "Vence en {$dias} día(s)")) . ' (' . $a->proximo->format('d/m/Y') . ') · $ ' . number_format($a->importe(), 0, ',', '.') . ($a->emitir_auto ? ' · se emite sola' : ' · queda en borrador para revisar'),
                    'url' => '/comprobantes/abonos', 'resuelta_en' => null]);
            $vivos[] = $a->id; $n++;
        }
        Alerta::withoutGlobalScopes()->where('tipo', 'abono_por_facturar')->whereNull('resuelta_en')->whereNotIn('modelo_id', $vivos)->update(['resuelta_en' => now()]);

        $borradores = [];
        foreach (\App\Models\Comprobante::withoutGlobalScopes()->with('contact:id,name')->whereNotNull('abono_id')->where('estado', 'borrador')->get() as $c) {
            Alerta::withoutGlobalScopes()->updateOrCreate(['business_id' => $c->business_id, 'tipo' => 'abono_borrador', 'modelo' => 'Comprobante', 'modelo_id' => $c->id],
                ['business_location_id' => $c->business_location_id, 'modulo' => 'comprobantes', 'severidad' => $c->created_at->lt(now()->subDays(2)) ? 'critica' : 'aviso', 'titulo' => 'Factura de abono sin emitir · ' . ($c->contact?->name ?? ''),
                    'detalle' => 'Quedó en borrador el ' . $c->created_at->format('d/m/Y') . ' por $ ' . number_format((float) $c->total, 0, ',', '.') . '. Revisala y emitila.', 'url' => "/comprobantes/{$c->id}", 'resuelta_en' => null]);
            $borradores[] = $c->id; $n++;
        }
        Alerta::withoutGlobalScopes()->where('tipo', 'abono_borrador')->whereNull('resuelta_en')->whereNotIn('modelo_id', $borradores)->update(['resuelta_en' => now()]);
        return $n;
    }
}
