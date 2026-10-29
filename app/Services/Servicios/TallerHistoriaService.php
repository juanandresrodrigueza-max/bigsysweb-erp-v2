<?php

namespace App\Services\Servicios;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\OrdenTrabajo;
use App\Models\OrdenTrabajoFoto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// Fase 36.2, 36.5 y 36.6: la recepción con fotos (a mano o con IA) y el acta; que el equipo vuelva (por tiempo, por medidor y por pieza);
// la línea de vida del equipo y del cliente, el cambio de dueño y lo que hay que revisar.
class TallerHistoriaService
{
    public const CHECKLIST = [
        'taller' => ['Luces', 'Frenos', 'Neumáticos', 'Aceite', 'Refrigerante', 'Batería', 'Limpiaparabrisas', 'Tapizado'],
        'celulares' => ['Pantalla', 'Táctil', 'Cámaras', 'Carga', 'Botones', 'Audio', 'Wi-Fi', 'Carcasa'],
        'default' => ['Enciende', 'Estado general', 'Accesorios', 'Carcasa'],
    ];

    public function checklist(?string $rubro): array { return self::CHECKLIST[$rubro] ?? self::CHECKLIST['default']; }

    // 36.2: la recepción a mano (o corrigiendo lo que dijo la IA). Guarda medidor, combustible, daños, checklist en semáforo y trabajos para ofrecer.
    public function guardarRecepcion(OrdenTrabajo $o, array $d): OrdenTrabajo
    {
        $r = array_merge($o->recepcion ?? [], collect($d)->only(['combustible', 'luces', 'danios', 'checklist', 'sugeridos', 'observaciones', 'patente_leida', 'ia'])->all());
        $r['comparacion'] = $this->comparar($o, $r, isset($d['medidor']) ? (float) $d['medidor'] : ($o->medidor !== null ? (float) $o->medidor : null));
        $o->update(['recepcion' => $r] + (isset($d['medidor']) && $d['medidor'] !== '' ? ['medidor' => (float) $d['medidor']] : []));
        AuditLog::registrar('editar', $o, "Recepción de OT {$o->numeroFormateado()}");
        return $o->fresh();
    }

    // La IA mira las fotos de recepción y completa lo que puede: km, combustible, luces, patente, daños con foto, checklist y trabajos para ofrecer.
    public function recepcionConIA(OrdenTrabajo $o): array
    {
        $key = config('services.anthropic.api_key');
        abort_if(! $key, 422, 'Para leer las fotos con IA falta la clave de IA en la configuración. Podés completar la recepción a mano.');
        $fotos = $o->fotos()->where('momento', 'recepcion')->get()->filter(fn($f) => Storage::exists($f->path) && preg_match('/\.(jpe?g|png|webp)$/i', $f->path))->take(8);
        abort_if($fotos->isEmpty(), 422, 'Subí primero las fotos de recepción (frente, laterales, tablero).');
        $cfg = app(TallerService::class)->config(); $check = $this->checklist($cfg['rubro']);
        $content = [];
        foreach ($fotos as $f) { $content[] = ['type' => 'text', 'text' => "Foto {$f->id}:"]; $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => Storage::mimeType($f->path) ?: 'image/jpeg', 'data' => base64_encode(Storage::get($f->path))]]; }
        $content[] = ['type' => 'text', 'text' => "Sos el recepcionista de un {$cfg['equipo']} en un taller argentino. Mirá las fotos de recepción y devolvé SOLO un JSON: {\"medidor\": número o null ({$cfg['medidor']} del tablero), \"combustible\": \"vacío|1/4|1/2|3/4|lleno\" o null, \"luces\": [\"testigos encendidos\"], \"patente_leida\": \"...\" o null, \"danios\": [{\"zona\":\"...\",\"detalle\":\"...\",\"foto_id\":N}], \"checklist\": {" . implode(', ', array_map(fn($c) => "\"{$c}\": \"verde|amarillo|rojo\"", $check)) . "}, \"sugeridos\": [\"trabajos para ofrecer\"]}. Si algo no se ve, null o vacío. Sin comentarios."];
        $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(90)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model', 'claude-sonnet-5'), 'max_tokens' => 1500, 'messages' => [['role' => 'user', 'content' => $content]]]);
        abort_if(! $r->successful(), 422, 'La IA no pudo leer las fotos. Completá la recepción a mano.');
        $t = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $j = json_decode(substr($t, strpos($t, '{'), strrpos($t, '}') - strpos($t, '{') + 1), true) ?: [];
        $ids = $fotos->pluck('id')->all();
        $j['danios'] = collect($j['danios'] ?? [])->map(fn($x) => ['zona' => (string) ($x['zona'] ?? ''), 'detalle' => (string) ($x['detalle'] ?? ''), 'foto_id' => in_array((int) ($x['foto_id'] ?? 0), $ids, true) ? (int) $x['foto_id'] : null])->values()->all();
        $j['checklist'] = collect($j['checklist'] ?? [])->only($check)->map(fn($v) => in_array($v, ['verde', 'amarillo', 'rojo'], true) ? $v : null)->filter()->all();
        $j['ia'] = true;
        $this->guardarRecepcion($o, $j + ['medidor' => $j['medidor'] ?? null]);
        return $j;
    }

    // Comparar con la visita anterior del mismo equipo: cuánto anduvo y qué daños son nuevos.
    private function comparar(OrdenTrabajo $o, array $r, ?float $medidor): ?array
    {
        if (! $o->serie) return null;
        $ant = OrdenTrabajo::where('serie', $o->serie)->where('id', '<', $o->id)->latest('id')->first();
        if (! $ant) return null;
        $danAnt = collect($ant->recepcion['danios'] ?? [])->map(fn($d) => mb_strtolower($d['zona'] ?? ''))->all();
        $nuevos = collect($r['danios'] ?? [])->filter(fn($d) => ! in_array(mb_strtolower($d['zona'] ?? ''), $danAnt, true))->pluck('zona')->values()->all();
        $dias = (int) $ant->fecha_ingreso->diffInDays($o->fecha_ingreso);
        $anduvo = $medidor !== null && $ant->medidor !== null ? round($medidor - (float) $ant->medidor, 1) : null;
        return ['ot_anterior' => $ant->numeroFormateado(), 'fecha_anterior' => $ant->fecha_ingreso->format('d/m/Y'), 'dias' => $dias, 'anduvo' => $anduvo, 'por_dia' => $anduvo !== null && $dias > 0 ? round($anduvo / $dias, 1) : null, 'danios_nuevos' => $nuevos, 'medidor_baja' => $anduvo !== null && $anduvo < 0];
    }

    // 36.5: cuándo tiene que volver. Por tiempo (meses) o por medidor; si es por medidor, se estima la fecha con el uso de las visitas.
    public function programarVuelta(OrdenTrabajo $o, ?int $meses, ?float $medidor): OrdenTrabajo
    {
        $o->update(['volver_el' => $meses ? $o->fecha_ingreso->copy()->addMonths($meses) : null, 'volver_medidor' => $medidor, 'recordado_en' => null]);
        return $o->fresh();
    }

    // Uso diario estimado del equipo con sus visitas (km/día u horas/día).
    public function usoDiario(?string $serie): ?float
    {
        if (! $serie) return null;
        $v = OrdenTrabajo::where('serie', $serie)->whereNotNull('medidor')->orderBy('fecha_ingreso')->get(['fecha_ingreso', 'medidor']);
        if ($v->count() < 2) return null;
        $dias = $v->first()->fecha_ingreso->diffInDays($v->last()->fecha_ingreso);
        return $dias > 0 ? round(((float) $v->last()->medidor - (float) $v->first()->medidor) / $dias, 2) : null;
    }

    // La pestaña "Que vuelvan": equipos a los que les toca, por fecha, por medidor estimado o por pieza (la correa cada 60.000 km).
    public function queVuelvan(int $dias = 30): array
    {
        $cfg = Auth::user()->business()->first()->servicios_config ?? []; $piezas = $cfg['piezas'] ?? [];
        $ultimas = OrdenTrabajo::with('contact:id,name,phone,mobile', 'items')->whereIn('id', OrdenTrabajo::whereNotNull('serie')->where('estado', 'entregado')->selectRaw('MAX(id)')->groupBy('serie'))->get();
        $out = [];
        foreach ($ultimas as $o) {
            $uso = $this->usoDiario($o->serie); $motivos = []; $cuando = null;
            if ($o->volver_el && $o->volver_el->lte(today()->addDays($dias))) { $motivos[] = 'service programado para el ' . $o->volver_el->format('d/m/Y'); $cuando = $o->volver_el; }
            if ($o->volver_medidor && $o->medidor !== null && $uso) {
                $fecha = $o->fecha_ingreso->copy()->addDays((int) ceil(((float) $o->volver_medidor - (float) $o->medidor) / $uso));
                if ($fecha->lte(today()->addDays($dias))) { $motivos[] = 'llega a ' . number_format((float) $o->volver_medidor, 0, ',', '.') . ' ' . ($cfg['medidor'] ?? 'km') . ' cerca del ' . $fecha->format('d/m/Y') . ' (usa ~' . round($uso) . ' por día)'; $cuando = $cuando ? min($cuando, $fecha) : $fecha; }
            }
            // Por pieza: la última vez que se cambió en este equipo, más el intervalo.
            foreach ($piezas as $pz) {
                if (empty($pz['nombre']) || empty($pz['cada']) || ! $uso) continue;
                $ot = OrdenTrabajo::with('items')->where('serie', $o->serie)->whereNotNull('medidor')->where('estado', 'entregado')->latest('id')->get()->first(fn($x) => $x->items->contains(fn($i) => $i->aprobado !== false && str_contains(mb_strtolower($i->descripcion), mb_strtolower($pz['nombre']))));
                $base = $ot ? (float) $ot->medidor : 0.0; $toca = $base + (float) $pz['cada'];
                $estimado = $o->medidor !== null ? (float) $o->medidor + $uso * $o->fecha_ingreso->diffInDays(today()) : null;
                if ($estimado !== null && $estimado >= $toca - $uso * $dias) $motivos[] = "{$pz['nombre']}: toca a los " . number_format($toca, 0, ',', '.') . ' (anda por los ' . number_format($estimado, 0, ',', '.') . ')';
            }
            if (! $motivos) continue;
            $tel = $o->contact?->mobile ?: $o->contact?->phone ?: $o->telefono;
            $texto = "Hola {$o->clienteNombre()}! Te escribimos de {$o->business->name}: a tu {$o->equipo}" . ($o->serie ? " ({$o->serie})" : '') . " le toca: " . implode('; ', $motivos) . '. ¿Te reservamos un turno?';
            $out[] = ['ot_id' => $o->id, 'cliente' => $o->clienteNombre(), 'equipo' => $o->equipo, 'serie' => $o->serie, 'motivos' => $motivos, 'cuando' => $cuando?->format('d/m/Y'), 'recordado' => $o->recordado_en?->format('d/m/Y'), 'telefono' => $tel, 'whatsapp' => $tel ? 'https://wa.me/' . preg_replace('/\D/', '', $tel) . '?text=' . rawurlencode($texto) : null, 'texto' => $texto];
        }
        return collect($out)->sortBy(fn($x) => $x['cuando'] ? \Carbon\Carbon::createFromFormat('d/m/Y', $x['cuando'])->timestamp : 0)->values()->all();
    }

    public function marcarRecordado(OrdenTrabajo $o): void { $o->update(['recordado_en' => now()]); AuditLog::registrar('editar', $o, "Avisó al cliente que le toca volver ({$o->numeroFormateado()})"); }

    // 36.6: la línea de vida del equipo (todas sus visitas) y del cliente (sus equipos: una flota).
    public function vidaEquipo(OrdenTrabajo $o): array
    {
        if (! $o->serie) return [];
        return OrdenTrabajo::with('items', 'contact:id,name')->where('serie', $o->serie)->orderByDesc('fecha_ingreso')->get()->map(fn($x) => ['id' => $x->id, 'numero' => $x->numeroFormateado(), 'fecha' => $x->fecha_ingreso->format('d/m/Y'), 'medidor' => $x->medidor !== null ? (float) $x->medidor : null, 'estado' => $x->estado, 'dueno' => $x->clienteNombre(), 'falla' => $x->falla, 'trabajos' => $x->items->filter(fn($i) => $i->aprobado !== false)->pluck('descripcion')->all(), 'total' => (float) $x->presupuesto, 'actual' => $x->id === $o->id])->all();
    }

    public function flota(?int $contactId): array
    {
        if (! $contactId) return [];
        return OrdenTrabajo::where('contact_id', $contactId)->orderByDesc('fecha_ingreso')->get()->groupBy(fn($x) => $x->serie ?: 'sin-' . $x->id)->map(fn($g) => ['serie' => $g->first()->serie, 'equipo' => $g->first()->equipo, 'marca_modelo' => $g->first()->marca_modelo, 'visitas' => $g->count(), 'ultima' => $g->first()->fecha_ingreso->format('d/m/Y'), 'ultima_id' => $g->first()->id, 'gastado' => round((float) $g->where('estado', 'entregado')->sum('presupuesto'), 2)])->values()->all();
    }

    // Cambio de dueño: el equipo se vendió. Esta orden y las abiertas del equipo pasan al nuevo; la historia queda.
    public function cambiarDueno(OrdenTrabajo $o, Contact $nuevo): int
    {
        abort_if(! $o->serie, 422, 'Para cambiar de dueño el equipo tiene que tener su número (patente, serie).');
        $antes = $o->clienteNombre();
        $n = OrdenTrabajo::where('serie', $o->serie)->whereNotIn('estado', ['entregado', 'cancelado'])->update(['contact_id' => $nuevo->id, 'nombre' => null, 'telefono' => $nuevo->mobile ?: $nuevo->phone]);
        $o->update(['notas' => trim(($o->notas ?? '') . "\n" . now()->format('d/m/Y') . " Cambio de dueño: de {$antes} a {$nuevo->name}")]);
        AuditLog::registrar('editar', $o, "Cambio de dueño de {$o->equipo} {$o->serie}: de {$antes} a {$nuevo->name}");
        return $n;
    }

    // "Para revisar": lo que se está quedando, sin que nadie lo tenga que buscar.
    public function paraRevisar(): array
    {
        $hoy = now(); $out = [];
        foreach (OrdenTrabajo::with('contact:id,name')->whereNotIn('estado', ['cancelado'])->where(fn($q) => $q->where('estado', '!=', 'entregado')->orWhereNull('comprobante_id'))->get() as $o) {
            $dUpd = (int) $o->updated_at->diffInDays($hoy);
            $m = match (true) {
                $o->estado === 'listo' && $dUpd >= 7 => "Lista hace {$dUpd} días y nadie la retiró",
                $o->estado === 'esperando' && $o->esperando_desde && $o->esperando_desde->diffInDays($hoy) >= 5 => 'Esperando hace ' . (int) $o->esperando_desde->diffInDays($hoy) . " días: {$o->esperando_motivo}",
                $o->estado === 'presupuestado' && $dUpd >= 3 => "El cliente no respondió el presupuesto hace {$dUpd} días",
                in_array($o->estado, ['aprobado', 'en_curso'], true) && $dUpd >= 5 => "Aprobada y sin movimiento hace {$dUpd} días",
                $o->estado === 'entregado' && ! $o->comprobante_id => 'Entregada sin factura ni remito',
                $o->fecha_prometida && $o->fecha_prometida->isPast() && ! in_array($o->estado, ['listo', 'entregado'], true) => 'Pasó la fecha prometida (' . $o->fecha_prometida->format('d/m') . ')',
                default => null,
            };
            if ($m) $out[] = ['id' => $o->id, 'numero' => $o->numeroFormateado(), 'cliente' => $o->clienteNombre(), 'equipo' => $o->equipo, 'motivo' => $m];
        }
        return $out;
    }
}
