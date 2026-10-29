<?php

namespace App\Services\Servicios;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\OrdenTrabajo;
use App\Models\OrdenTrabajoItem;
use App\Models\ServicioPlantilla;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Fase 36: el taller de punta a punta. "¿Qué reparás?" arma las palabras y los datos de cada equipo; cada orden dice qué sigue;
// el cliente aprueba ítem por ítem (y un cambio de importes anula la aprobación); la mano de obra se reparte por % entre técnicos.
class TallerService
{
    public const RUBROS = [
        'taller' => ['nombre' => 'Taller mecánico', 'equipo' => 'Vehículo', 'serie' => 'Patente', 'medidor' => 'Km', 'tecnico' => 'Mecánico', 'campos' => ['marca', 'modelo', 'año', 'chasis', 'motor', 'color']],
        'celulares' => ['nombre' => 'Celulares y tablets', 'equipo' => 'Equipo', 'serie' => 'IMEI', 'medidor' => null, 'tecnico' => 'Técnico', 'campos' => ['marca', 'modelo', 'color', 'clave de desbloqueo']],
        'electronica' => ['nombre' => 'Electrónica y computación', 'equipo' => 'Equipo', 'serie' => 'N° de serie', 'medidor' => null, 'tecnico' => 'Técnico', 'campos' => ['marca', 'modelo', 'accesorios']],
        'electrodomesticos' => ['nombre' => 'Electrodomésticos', 'equipo' => 'Artefacto', 'serie' => 'N° de serie', 'medidor' => null, 'tecnico' => 'Técnico', 'campos' => ['marca', 'modelo', 'tipo']],
        'bicicletas' => ['nombre' => 'Bicicletas y motos', 'equipo' => 'Bicicleta', 'serie' => 'N° de cuadro', 'medidor' => 'Km', 'tecnico' => 'Mecánico', 'campos' => ['marca', 'modelo', 'rodado', 'color']],
        'maquinaria' => ['nombre' => 'Maquinaria y herramientas', 'equipo' => 'Máquina', 'serie' => 'N° de serie', 'medidor' => 'Horas', 'tecnico' => 'Técnico', 'campos' => ['marca', 'modelo', 'año']],
        'otro' => ['nombre' => 'Otro', 'equipo' => 'Equipo', 'serie' => 'N° de serie', 'medidor' => null, 'tecnico' => 'Técnico', 'campos' => ['marca', 'modelo']],
    ];

    public function config(?Business $b = null): array
    {
        $b ??= Auth::user()->business()->first();
        $c = $b->servicios_config ?? [];
        $base = self::RUBROS[$c['rubro'] ?? 'otro'] ?? self::RUBROS['otro'];
        return ['rubro' => $c['rubro'] ?? null, 'configurado' => isset($c['rubro']), 'equipo' => $c['equipo'] ?? $base['equipo'], 'serie' => $c['serie'] ?? $base['serie'], 'medidor' => array_key_exists('medidor', $c) ? $c['medidor'] : $base['medidor'], 'tecnico' => $c['tecnico'] ?? $base['tecnico'], 'campos' => $c['campos'] ?? $base['campos'], 'pago_tecnico_pct' => (float) ($c['pago_tecnico_pct'] ?? 40)];
    }

    // "¿Qué reparás?": con un toque quedan las palabras y los datos; después se puede retocar cada uno.
    public function guardarConfig(array $d): array
    {
        $b = Auth::user()->business()->first(); $base = self::RUBROS[$d['rubro']] ?? self::RUBROS['otro'];
        $b->servicios_config = ['rubro' => $d['rubro'], 'equipo' => $d['equipo'] ?? $base['equipo'], 'serie' => $d['serie'] ?? $base['serie'], 'medidor' => array_key_exists('medidor', $d) ? ($d['medidor'] ?: null) : $base['medidor'], 'tecnico' => $d['tecnico'] ?? $base['tecnico'], 'campos' => array_values(array_filter(array_map('trim', (array) ($d['campos'] ?? $base['campos'])))), 'pago_tecnico_pct' => (float) ($d['pago_tecnico_pct'] ?? 40)];
        $b->save();
        AuditLog::registrar('editar', $b, 'Servicio técnico: rubro ' . ($base['nombre']));
        return $this->config($b);
    }

    // La barra "Qué sigue": el próximo paso de cada orden, en criollo.
    public function queSigue(OrdenTrabajo $o): array
    {
        $items = $o->relationLoaded('items') ? $o->items : $o->items()->get();
        $tareas = $o->relationLoaded('tareas') ? $o->tareas : $o->tareas()->get();
        $pend = $tareas->where('hecha', false)->count();
        return match ($o->estado) {
            'recibido' => ! $o->diagnostico ? ['paso' => 'Diagnosticar', 'accion' => 'diagnostico'] : ($items->isEmpty() ? ['paso' => 'Cargar los repuestos y la mano de obra', 'accion' => 'items'] : ['paso' => 'Mandar el presupuesto al cliente', 'accion' => 'presupuestado']),
            'diagnostico' => $items->isEmpty() ? ['paso' => 'Armar el presupuesto', 'accion' => 'items'] : ['paso' => 'Mandar el presupuesto al cliente', 'accion' => 'presupuestado'],
            'presupuestado' => ['paso' => 'Esperar que el cliente apruebe (mandale el link)', 'accion' => 'link'],
            'aprobado' => ['paso' => 'Arrancar la reparación', 'accion' => 'en_curso'],
            'en_curso' => $pend ? ['paso' => "Terminar {$pend} tarea" . ($pend === 1 ? '' : 's'), 'accion' => 'tareas'] : ['paso' => 'Marcar listo y avisar', 'accion' => 'listo'],
            'esperando' => ['paso' => 'Esperando: ' . ($o->esperando_motivo ?: 'sin motivo') . ($o->esperando_desde ? ' (' . ((int) $o->esperando_desde->diffInDays(now()) === 0 ? 'desde hoy' : 'hace ' . (int) $o->esperando_desde->diffInDays(now()) . ' días') . ')' : ''), 'accion' => 'retomar'],
            'listo' => ['paso' => 'Entregar y facturar', 'accion' => 'facturar'],
            default => ['paso' => null, 'accion' => null],
        };
    }

    public function esperar(OrdenTrabajo $o, string $motivo): void
    {
        abort_if(in_array($o->estado, ['entregado', 'cancelado'], true), 422, 'La orden está cerrada.');
        $o->update(['estado' => 'esperando', 'esperando_motivo' => mb_substr($motivo, 0, 160), 'esperando_desde' => now(), 'notas' => trim(($o->notas ?? '') . "\n" . now()->format('d/m') . " Esperando: {$motivo}")]);
        AuditLog::registrar('editar', $o, "OT {$o->numeroFormateado()} esperando: {$motivo}");
    }

    // Lo que suma lo aprobado (o todo, si nadie respondió todavía).
    public function totalAprobado(OrdenTrabajo $o): float
    {
        return round((float) $o->items()->where(fn($q) => $q->whereNull('aprobado')->orWhere('aprobado', true))->sum('total'), 2);
    }

    // La huella de los importes: si cambia después de aprobar, la aprobación ya no vale.
    public function huella(OrdenTrabajo $o): string
    {
        return md5($o->items()->orderBy('id')->get(['id', 'cantidad', 'precio_unit', 'total'])->map(fn($i) => $i->id . ':' . round((float) $i->total, 2))->implode('|'));
    }

    // 36.3: el cliente destilda lo que deja para después y firma. Lo no aprobado queda pendiente para la próxima visita.
    public function aprobarPorItem(OrdenTrabajo $o, array $aprobados, ?string $nombre, ?string $firma): void
    {
        abort_if(! in_array($o->estado, ['presupuestado', 'diagnostico', 'recibido'], true), 422, 'El presupuesto ya fue respondido.');
        DB::transaction(function () use ($o, $aprobados, $nombre, $firma) {
            $ids = array_map('intval', $aprobados);
            foreach ($o->items as $it) $it->update(['aprobado' => in_array($it->id, $ids, true), 'pendiente_proxima' => ! in_array($it->id, $ids, true)]);
            $total = $this->totalAprobado($o->fresh());
            abort_if($o->items->count() && $total <= 0, 422, 'Tildá al menos un trabajo para aprobar, o tocá «No, gracias».');
            $o->update(['estado' => 'aprobado', 'aprobado_en' => now(), 'firma_nombre' => $nombre, 'presupuesto' => $total ?: $o->presupuesto, 'aprobacion' => ['items' => $ids, 'total' => $total, 'nombre' => $nombre, 'firma' => $firma, 'cuando' => now()->format('d/m/Y H:i'), 'huella' => $this->huella($o->fresh())]]);
            $no = $o->items->whereNotIn('id', $ids)->count();
            AuditLog::registrar('editar', $o, "El cliente aprobó " . count($ids) . " ítems de OT {$o->numeroFormateado()}" . ($no ? " y dejó {$no} para la próxima" : '') . ' · $ ' . number_format($total, 2, ',', '.'));
        });
    }

    // Después de cada cambio en la hoja: si estaba aprobada y los importes cambiaron, se anula y vuelve a presupuestado.
    public function revisarAprobacion(OrdenTrabajo $o): ?string
    {
        $o->refresh();
        if (! $o->aprobacion || ! in_array($o->estado, ['aprobado', 'en_curso', 'esperando'], true)) return null;
        if (($o->aprobacion['huella'] ?? null) === $this->huella($o)) return null;
        $o->items()->update(['aprobado' => null, 'pendiente_proxima' => false]);
        $o->update(['estado' => 'presupuestado', 'aprobado_en' => null, 'aprobacion' => null, 'presupuesto' => $this->totalAprobado($o)]);
        AuditLog::registrar('editar', $o, "Cambiaron los importes de OT {$o->numeroFormateado()}: la aprobación del cliente se anuló");
        return 'Cambiaron los importes: la aprobación del cliente quedó anulada. Mandale el presupuesto de nuevo.';
    }

    // Lo que el cliente dejó para después en visitas anteriores del mismo equipo (o del mismo cliente si no hay serie).
    public function pendientesAnteriores(OrdenTrabajo $o): array
    {
        return OrdenTrabajoItem::query()->join('ordenes_trabajo', 'ordenes_trabajo.id', '=', 'orden_trabajo_items.orden_trabajo_id')
            ->where('ordenes_trabajo.business_id', $o->business_id)->where('ordenes_trabajo.id', '!=', $o->id)->where('orden_trabajo_items.pendiente_proxima', true)
            ->where(fn($q) => $o->serie ? $q->where('ordenes_trabajo.serie', $o->serie) : $q->where('ordenes_trabajo.contact_id', $o->contact_id)->whereNotNull('ordenes_trabajo.contact_id'))
            ->select('orden_trabajo_items.*', 'ordenes_trabajo.numero as ot_numero', 'ordenes_trabajo.fecha_ingreso as ot_fecha')->get()
            ->map(fn($i) => ['id' => $i->id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'total' => (float) $i->total, 'ot' => 'OT-' . str_pad((string) $i->ot_numero, 5, '0', STR_PAD_LEFT), 'fecha' => \Carbon\Carbon::parse($i->ot_fecha)->format('d/m/Y')])->all();
    }

    public function traerPendiente(OrdenTrabajo $o, int $itemId): void
    {
        $i = OrdenTrabajoItem::where('pendiente_proxima', true)->findOrFail($itemId);
        abort_if(OrdenTrabajo::find($i->orden_trabajo_id)?->business_id !== $o->business_id, 404);
        DB::transaction(function () use ($o, $i) {
            $o->items()->create(['product_id' => $i->product_id, 'tipo' => $i->tipo, 'descripcion' => $i->descripcion, 'cantidad' => $i->cantidad, 'precio_unit' => $i->precio_unit, 'total' => $i->total, 'viene_de_item_id' => $i->id, 'tecnicos' => $i->tecnicos]);
            $i->update(['pendiente_proxima' => false]);
        });
    }

    // 36.4: la mano de obra repartida por % entre técnicos (tienen que sumar 100).
    public function repartir(OrdenTrabajoItem $it, array $reparto): void
    {
        $r = collect($reparto)->filter(fn($x) => ! empty($x['user_id']) && (float) ($x['pct'] ?? 0) > 0)->map(fn($x) => ['user_id' => (int) $x['user_id'], 'pct' => round((float) $x['pct'], 2)])->values();
        if ($r->isNotEmpty() && abs($r->sum('pct') - 100) > 0.01) throw ValidationException::withMessages(['reparto' => 'Los porcentajes tienen que sumar 100 (suman ' . $r->sum('pct') . ').']);
        $it->update(['tecnicos' => $r->isEmpty() ? null : $r->all()]);
    }

    private function repartoDe(OrdenTrabajoItem $it, OrdenTrabajo $o): array
    {
        return $it->tecnicos ?: ($o->tecnico_id ? [['user_id' => $o->tecnico_id, 'pct' => 100]] : []);
    }

    public function guardarPlantilla(OrdenTrabajo $o, string $nombre): ServicioPlantilla
    {
        $items = $o->items()->get()->map(fn($i) => ['product_id' => $i->product_id, 'tipo' => $i->tipo, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit])->all();
        abort_if(! $items, 422, 'La hoja está vacía.');
        return ServicioPlantilla::create(['business_id' => $o->business_id, 'nombre' => $nombre, 'items' => $items]);
    }

    public function aplicarPlantilla(OrdenTrabajo $o, ServicioPlantilla $p, OrdenesService $ordenes): int
    {
        foreach ($p->items as $i) $ordenes->item($o, $i + ['actualizar_presupuesto' => true]);
        return count($p->items);
    }

    // "Mis órdenes": lo de cada técnico, con el próximo paso.
    public function misOrdenes(User $u): array
    {
        return OrdenTrabajo::with('contact:id,name', 'items', 'tareas')->whereNotIn('estado', ['entregado', 'cancelado'])->get()
            ->filter(fn($o) => $o->tecnico_id === $u->id || $o->items->contains(fn($i) => collect($i->tecnicos ?? [])->contains('user_id', $u->id)))
            ->sortBy(fn($o) => [$o->prioridad === 'alta' ? 0 : 1, $o->fecha_prometida?->timestamp ?? PHP_INT_MAX])->values()
            ->map(fn($o) => ['id' => $o->id, 'numero' => $o->numeroFormateado(), 'cliente' => $o->clienteNombre(), 'equipo' => $o->equipo, 'marca_modelo' => $o->marca_modelo, 'estado' => $o->estado, 'prioridad' => $o->prioridad, 'prometida' => $o->fecha_prometida?->format('d/m'), 'atrasada' => $o->fecha_prometida && $o->fecha_prometida->isPast(), 'que_sigue' => $this->queSigue($o)['paso']])->all();
    }

    // Lo que hay que pagarle a cada técnico: su parte de la mano de obra aprobada de las órdenes entregadas en el período × el % de pago.
    public function pagoTecnicos(string $desde, string $hasta): array
    {
        $pct = $this->config()['pago_tecnico_pct'];
        $ots = OrdenTrabajo::with('items')->where('estado', 'entregado')->whereBetween('entregado_en', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])->get();
        $por = [];
        foreach ($ots as $o) foreach ($o->items->where('tipo', 'mano_obra')->filter(fn($i) => $i->aprobado !== false) as $it) foreach ($this->repartoDe($it, $o) as $r) {
            $monto = round((float) $it->total * $r['pct'] / 100, 2);
            $por[$r['user_id']] ??= ['user_id' => $r['user_id'], 'mano_obra' => 0, 'ordenes' => [], 'detalle' => []];
            $por[$r['user_id']]['mano_obra'] += $monto; $por[$r['user_id']]['ordenes'][$o->id] = true;
            $por[$r['user_id']]['detalle'][] = ['ot' => $o->numeroFormateado(), 'trabajo' => $it->descripcion, 'pct' => $r['pct'], 'monto' => $monto];
        }
        $nombres = User::whereIn('id', array_keys($por))->pluck('name', 'id');
        return ['pct' => $pct, 'tecnicos' => collect($por)->map(fn($p) => ['tecnico' => $nombres[$p['user_id']] ?? '—', 'user_id' => $p['user_id'], 'ordenes' => count($p['ordenes']), 'mano_obra' => round($p['mano_obra'], 2), 'a_pagar' => round($p['mano_obra'] * $pct / 100, 2), 'detalle' => $p['detalle']])->sortByDesc('mano_obra')->values()->all()];
    }
}
