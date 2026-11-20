<?php

namespace App\Services\Empresas;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Fase 113: eliminar una empresa entera (sólo superadmin, con el nombre escrito y la clave de aprobación). Es definitivo: borra todas
// sus filas en todas las tablas y sus usuarios. Recorre el esquema solo: las tablas con `business_id` son directas; las que apuntan
// por clave foránea a una tabla ya alcanzada (renglones de comprobantes, pivotes, avisos de usuarios) se suman en cascada. Se
// borra en orden (primero las que referencian, después las referenciadas), todo en una transacción: o se borra todo o nada.
class EliminarEmpresaService
{
    // Tablas que no son datos de empresa: nunca se tocan aunque alguna tenga una referencia.
    public const EXCLUIDAS = ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens', 'plans', 'novedades', 'sistema_config', 'telescope_entries', 'pulse_entries'];

    // Qué se va a borrar: tabla → cómo se llega a ella. Se calcula del esquema, así sirve para las tablas que se agreguen después.
    public function mapa(): array
    {
        $tablas = collect(Schema::getTables())->pluck('name')->reject(fn($t) => in_array($t, self::EXCLUIDAS, true) || $t === 'businesses')->values()->all();
        $columnas = []; $fks = [];
        foreach ($tablas as $t) { $columnas[$t] = Schema::getColumnListing($t); $fks[$t] = Schema::getForeignKeys($t); }
        $mapa = [];
        foreach ($tablas as $t) if (in_array('business_id', $columnas[$t], true)) $mapa[$t] = ['via' => 'business_id'];
        // En cascada: una tabla sin business_id que referencia a una alcanzada entra por esa clave foránea (hasta que no cambie nada).
        do {
            $antes = count($mapa);
            foreach ($tablas as $t) {
                if (isset($mapa[$t])) continue;
                foreach ($fks[$t] as $fk) {
                    $ft = $fk['foreign_table'];
                    if (($ft === 'businesses' || isset($mapa[$ft])) && count($fk['columns']) === 1) { $mapa[$t] = $ft === 'businesses' ? ['via' => $fk['columns'][0]] : ['via' => 'fk', 'columna' => $fk['columns'][0], 'tabla' => $ft, 'referencia' => $fk['foreign_columns'][0]]; break; }
                }
            }
        } while (count($mapa) !== $antes);
        // Orden: la que referencia a otra alcanzada se borra antes. Kahn sobre el grafo de claves foráneas; los ciclos se cortan por nombre.
        $deps = [];
        foreach ($mapa as $t => $_) $deps[$t] = collect($fks[$t])->pluck('foreign_table')->filter(fn($f) => $f !== $t && isset($mapa[$f]))->unique()->values()->all();
        $orden = []; $pend = array_keys($mapa);
        while ($pend) {
            $libres = array_values(array_filter($pend, fn($t) => ! collect($pend)->contains(fn($o) => $o !== $t && in_array($t, $deps[$o], true))));
            if (! $libres) { sort($pend); $libres = [array_shift($pend)]; $pend[] = $libres[0]; }
            foreach ($libres as $t) { $orden[] = $t; $pend = array_values(array_diff($pend, [$t])); }
        }
        return ['mapa' => $mapa, 'orden' => $orden, 'tiene_id' => array_map(fn($t) => in_array('id', $columnas[$t], true), array_combine($orden, $orden))];
    }

    // Cuántas filas tiene la empresa en cada tabla (para mostrar antes de borrar).
    public function plan(Business $b): array
    {
        $m = $this->mapa(); $ids = []; $out = [];
        foreach (array_reverse($m['orden']) as $t) { // de referenciadas a referenciantes, para que las cascadas encuentren los ids
            $q = $this->consulta($t, $m['mapa'][$t], $b->id, $ids);
            $n = (clone $q)->count();
            if ($m['tiene_id'][$t]) $ids[$t] = $n ? $q->pluck('id')->all() : [];
            if ($n) $out[$t] = $n;
        }
        arsort($out);
        return $out;
    }

    public function eliminar(Business $b, User $por): array
    {
        if (! $por->is_superadmin) throw ValidationException::withMessages(['empresa' => 'Sólo el administrador de BigSys puede eliminar una empresa.']);
        if (($propia = app(\App\Services\Suscripciones\EmpresaBigSysService::class)->actual()) && $propia->id === $b->id) throw ValidationException::withMessages(['empresa' => 'Esa es la empresa de BigSys: no se elimina desde acá.']);
        if ($por->business_id === $b->id) throw ValidationException::withMessages(['empresa' => 'No podés eliminar la empresa en la que estás.']);

        // Fase 121.1: si facturaba a través de BigSys, antes de borrar se le avisa al dueño cómo revocar la relación en ARCA.
        try { \App\Services\Afip\ArcaAutorizaciones::alDarDeBaja($b, 'eliminación definitiva'); } catch (\Throwable $e) { report($e); }
        $m = $this->mapa(); $ids = []; $borrado = [];
        // Primero se juntan los ids (de referenciadas a referenciantes); después se borra al revés, en una sola transacción.
        foreach (array_reverse($m['orden']) as $t) if ($m['tiene_id'][$t]) $ids[$t] = $this->consulta($t, $m['mapa'][$t], $b->id, $ids)->pluck('id')->all();
        $nombre = $b->name; $cuit = $b->cuit; $id = $b->id;
        DB::transaction(function () use ($m, $ids, $b, &$borrado, $nombre, $por) {
            $pendientes = $m['orden']; $intentos = 0;
            while ($pendientes && $intentos++ < 6) {
                $quedan = [];
                foreach ($pendientes as $t) {
                    try {
                        $n = $m['tiene_id'][$t]
                            ? collect($ids[$t])->chunk(500)->sum(fn($c) => DB::table($t)->whereIn('id', $c->all())->delete())
                            : $this->consulta($t, $m['mapa'][$t], $b->id, $ids)->delete();
                        if ($n) $borrado[$t] = $n;
                    } catch (QueryException $e) { $quedan[] = $t; $ultimo = $e; }
                }
                if (count($quedan) === count($pendientes)) throw new \RuntimeException('No se pudo borrar ' . implode(', ', $quedan) . ': ' . ($ultimo?->getMessage() ?? ''));
                $pendientes = $quedan;
            }
            DB::table('businesses')->where('id', $b->id)->delete();
            AuditLog::create(['business_id' => null, 'user_id' => $por->id, 'accion' => 'eliminar', 'modelo' => 'Business', 'modelo_id' => $b->id, 'ip' => request()?->ip(), 'created_at' => now(), 'descripcion' => mb_substr("Empresa eliminada definitivamente: {$nombre} (#{$b->id}), " . array_sum($borrado) . ' filas en ' . count($borrado) . ' tablas (superadmin)', 0, 250)]);
        });
        foreach (['logos', 'sueldos', 'relevamientos', 'adjuntos', 'afip', 'arca', 'empresas', 'importaciones', 'comprobantes'] as $dir) {
            try { if (Storage::exists("{$dir}/{$id}")) Storage::deleteDirectory("{$dir}/{$id}"); } catch (\Throwable) {}
        }
        return ['empresa' => $nombre, 'cuit' => $cuit, 'filas' => array_sum($borrado), 'tablas' => count($borrado), 'detalle' => $borrado];
    }

    private function consulta(string $t, array $regla, int $businessId, array $ids)
    {
        $q = DB::table($t);
        if ($regla['via'] === 'fk') return $q->whereIn($regla['columna'], $ids[$regla['tabla']] ?? []);
        return $q->where($regla['via'], $businessId);
    }
}
