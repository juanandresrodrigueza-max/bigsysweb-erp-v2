<?php

namespace App\Services\Estadisticas;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Product;
use App\Models\TurnoCaja;
use App\Support\Sql;
use Illuminate\Support\Carbon;

// Fase 33.6 · Qué hacer: los hallazgos de la semana (lo que no sabés y te cuesta plata, con el número y qué hacer) y el control de usuarios.
class QueHacerService
{
    public function hallazgos(Business $b): array
    {
        $out = [];
        // Listas de precios al revés: una lista mayorista (2 a 6) más cara que la lista 1.
        $alReves = [];
        foreach (Product::where('active', true)->whereNotNull('prices')->get() as $p) {
            $l1 = (float) $p->price; if ($l1 <= 0) continue;
            foreach ([2, 3, 4, 5, 6] as $l) { $v = $p->prices[(string) $l] ?? $p->prices[$l] ?? null; if ($v !== null && $v !== '' && (float) $v > $l1 + 0.005) { $alReves[] = ['id' => $p->id, 'nombre' => $p->name, 'lista' => $l, 'precio' => (float) $v, 'lista1' => $l1]; break; } }
        }
        $out[] = ['clave' => 'listas', 'titulo' => 'Listas de precios al revés', 'numero' => count($alReves), 'texto' => count($alReves) ? count($alReves) . ' artículo(s) tienen una lista mayorista más cara que la lista 1: el mayorista paga más que el minorista.' : 'Ninguna lista mayorista supera a la lista 1.', 'que_hacer' => 'Revisá esos precios en Stock → Actualizar precios.', 'url' => '/stock', 'items' => array_slice($alReves, 0, 50), 'grave' => count($alReves) > 0];
        // Clientes que dejaron de comprar: 3 o más facturas en los 6 meses anteriores y nada en los últimos 60 días.
        $corte = today()->subDays(60); $ini = $corte->copy()->subMonths(6);
        $antes = Comprobante::ventas()->emitidos()->whereIn('tipo', TableroService::FACTURAS)->whereBetween('fecha', [$ini->toDateString(), $corte->toDateString()])->selectRaw('contact_id, COUNT(*) as n, SUM(total) as monto, MAX(fecha) as ultima')->groupBy('contact_id')->havingRaw('COUNT(*) >= 3')->get()->keyBy('contact_id');
        $recientes = Comprobante::ventas()->emitidos()->whereIn('tipo', TableroService::FACTURAS)->where('fecha', '>', $corte->toDateString())->distinct()->pluck('contact_id')->flip();
        $cf = Contact::where('name', Sql::like(), '%consumidor final%')->pluck('id')->flip();
        $dejaron = $antes->reject(fn($r, $cid) => isset($recientes[$cid]) || isset($cf[$cid]));
        $nombres = Contact::whereIn('id', $dejaron->keys())->get(['id', 'name', 'phone', 'mobile'])->keyBy('id');
        $items = $dejaron->map(fn($r, $cid) => ['id' => $cid, 'nombre' => $nombres[$cid]->name ?? "Cliente {$cid}", 'telefono' => $nombres[$cid]->mobile ?? $nombres[$cid]->phone ?? null, 'facturas' => (int) $r->n, 'monto' => (float) $r->monto, 'ultima' => (string) $r->ultima])->sortByDesc('monto')->values();
        $out[] = ['clave' => 'clientes', 'titulo' => 'Clientes que dejaron de comprar', 'numero' => $items->count(), 'texto' => $items->count() ? $items->count() . ' cliente(s) que compraban seguido no compran hace más de 60 días: ' . number_format($items->sum('monto'), 0, ',', '.') . ' facturados en los 6 meses anteriores.' : 'Los clientes que compraban seguido siguen comprando.', 'que_hacer' => 'Llamalos o mandales un WhatsApp; la ficha tiene lo que solían llevar.', 'url' => '/clientes', 'items' => $items->take(50)->all(), 'grave' => $items->count() > 0];
        // Deuda vieja: saldo vencido hace más de 60 días, por cliente.
        $vieja = Comprobante::ventas()->pendientesCobro()->whereNotIn('contact_id', $cf->keys()->all() ?: [0])->where('fecha_vto', '<', today()->subDays(60)->toDateString())->selectRaw('contact_id, SUM(saldo) as saldo, COUNT(*) as n, MIN(fecha_vto) as desde')->groupBy('contact_id')->get();
        $nombresD = Contact::whereIn('id', $vieja->pluck('contact_id'))->pluck('name', 'id');
        $itemsD = $vieja->map(fn($r) => ['id' => $r->contact_id, 'nombre' => $nombresD[$r->contact_id] ?? "Cliente {$r->contact_id}", 'saldo' => (float) $r->saldo, 'facturas' => (int) $r->n, 'desde' => (string) $r->desde])->sortByDesc('saldo')->values();
        $out[] = ['clave' => 'deuda', 'titulo' => 'Deuda vieja', 'numero' => $itemsD->count(), 'texto' => $itemsD->count() ? number_format($itemsD->sum('saldo'), 0, ',', '.') . ' vencidos hace más de 60 días en ' . $itemsD->count() . ' cliente(s).' : 'No hay deuda vencida hace más de 60 días.', 'que_hacer' => 'Mandá el recordatorio desde Clientes → Cobranzas o armá un plan de pago.', 'url' => '/clientes/cobranzas', 'items' => $itemsD->take(50)->all(), 'grave' => $itemsD->count() > 0];
        // Caja sin cerrar: turnos abiertos hace más de 24 horas.
        $turnos = TurnoCaja::with('cuenta:id,nombre', 'user:id,name')->whereNull('cierre')->where('apertura', '<', now()->subDay())->get();
        $out[] = ['clave' => 'caja', 'titulo' => 'Caja sin cerrar', 'numero' => $turnos->count(), 'texto' => $turnos->count() ? $turnos->count() . ' turno(s) abiertos hace más de un día: hasta que se cierran, las ventas de esos días quedan sin arqueo.' : 'Todas las cajas se cerraron.', 'que_hacer' => 'Cerrá el turno en Fondos → Cierres y contá la plata.', 'url' => '/fondos/cierres', 'items' => $turnos->map(fn($t) => ['id' => $t->id, 'caja' => $t->cuenta?->nombre, 'usuario' => $t->user?->name, 'desde' => $t->apertura->format('d/m H:i'), 'horas' => (int) $t->apertura->diffInHours(now())])->values()->all(), 'grave' => $turnos->count() > 0];
        // Artículos bajo su margen mínimo.
        $vig = app(VigilanciaService::class)->margenes($b, 1, 'rojo');
        $out[] = ['clave' => 'margen', 'titulo' => 'Artículos bajo su margen mínimo', 'numero' => $vig['cantidad']['rojo'], 'texto' => $vig['cantidad']['rojo'] ? $vig['cantidad']['rojo'] . ' artículo(s) están por debajo del margen mínimo que les pusiste.' : 'Todos los artículos vigilados cumplen su mínimo.', 'que_hacer' => 'Subí el precio o revisá el costo en Rentabilidad → Márgenes a vigilar.', 'url' => '/estadisticas/rentabilidad?color=rojo', 'items' => array_slice(array_map(fn($i) => ['id' => $i['id'], 'nombre' => $i['nombre'], 'margen' => $i['margen'], 'minimo' => $i['minimo']], $vig['items']), 0, 50), 'grave' => $vig['cantidad']['rojo'] > 0];
        return ['hallazgos' => $out, 'graves' => count(array_filter($out, fn($h) => $h['grave'])), 'semana' => today()->locale('es')->isoFormat('D [de] MMMM')];
    }

    // Control de usuarios: quién anuló, quién modificó comprobantes ya emitidos, qué se cargó a deshora (antes de las 7 o después de las 22, o domingo) o con fecha atrasada.
    public function controlUsuarios(Business $b, int $dias = 30): array
    {
        $desde = now()->subDays($dias);
        $anulados = AuditLog::where('accion', 'anular')->whereIn('modelo', ['Comprobante', 'Cobro', 'Pago'])->where('created_at', '>=', $desde)->with('user:id,name')->orderByDesc('id')->get();
        $modificados = AuditLog::where('accion', 'editar')->where('modelo', 'like', '%Comprobante')->where('created_at', '>=', $desde)->with('user:id,name')->orderByDesc('id')->get()
            ->filter(fn($l) => ($c = Comprobante::withoutGlobalScopes()->find($l->modelo_id)) && $c->emitido_en && $l->created_at->gt($c->emitido_en->copy()->addMinutes(5)));
        $comps = Comprobante::ventas()->emitidos()->where('emitido_en', '>=', $desde)->with('user:id,name')->get(['id', 'tipo', 'punto_venta', 'numero', 'fecha', 'emitido_en', 'created_at', 'user_id', 'total']);
        $deshora = $comps->filter(fn($c) => $c->emitido_en->hour < 7 || $c->emitido_en->hour >= 22 || $c->emitido_en->dayOfWeek === 0);
        $atrasados = $comps->filter(fn($c) => $c->fecha->lt($c->created_at->copy()->startOfDay()->subDay()));
        $fila = fn(Comprobante $c, string $motivo) => ['id' => $c->id, 'numero' => $c->nombreTipo() . ' ' . $c->numeroFormateado(), 'usuario' => $c->user?->name ?? '—', 'cuando' => $c->emitido_en->format('d/m H:i'), 'fecha' => $c->fecha->format('d/m/Y'), 'total' => (float) $c->total, 'motivo' => $motivo];
        $log = fn(AuditLog $l) => ['id' => $l->id, 'usuario' => $l->user?->name ?? '—', 'cuando' => $l->created_at->format('d/m H:i'), 'descripcion' => $l->descripcion, 'modelo' => class_basename((string) $l->modelo), 'modelo_id' => $l->modelo_id];
        $porUsuario = [];
        $suma = function (string $k, ?string $u) use (&$porUsuario) { $u = $u ?: '—'; $porUsuario[$u] ??= ['usuario' => $u, 'anulados' => 0, 'modificados' => 0, 'deshora' => 0, 'atrasados' => 0]; $porUsuario[$u][$k]++; };
        foreach ($anulados as $l) $suma('anulados', $l->user?->name);
        foreach ($modificados as $l) $suma('modificados', $l->user?->name);
        foreach ($deshora as $c) $suma('deshora', $c->user?->name);
        foreach ($atrasados as $c) $suma('atrasados', $c->user?->name);
        return ['dias' => $dias, 'por_usuario' => array_values($porUsuario),
            'anulados' => $anulados->take(100)->map($log)->values()->all(), 'modificados' => $modificados->take(100)->map($log)->values()->all(),
            'deshora' => $deshora->take(100)->map(fn($c) => $fila($c, $c->emitido_en->dayOfWeek === 0 ? 'domingo' : ($c->emitido_en->hour < 7 ? 'antes de las 7' : 'después de las 22')))->values()->all(),
            'atrasados' => $atrasados->take(100)->map(fn($c) => $fila($c, 'cargado el ' . $c->created_at->format('d/m') . ' con fecha ' . $c->fecha->format('d/m')))->values()->all(),
            'como' => 'Últimos ' . $dias . ' días. Anulados: lo que cada usuario anuló (comprobantes, cobros, pagos). Modificados: comprobantes editados después de emitidos. A deshora: emitidos antes de las 7, después de las 22 o un domingo. Fecha atrasada: cargados con una fecha anterior al día en que se cargaron.'];
    }
}
