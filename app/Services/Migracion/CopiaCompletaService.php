<?php

namespace App\Services\Migracion;

use App\Models\CopiaCompleta;
use App\Models\SistemaConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

// Fase 123: la copia completa de la base de datos, para recuperar el ERP entero si se pierde el servidor.
//
// Las copias por empresa (BackupService) sirven para devolverle sus datos a una empresa, pero no traen lo general del
// sistema (superadmins, planes, suscripciones, el certificado de ARCA, las claves de Panel → Sistema, novedades) ni la
// llave con la que el servidor cifra datos (APP_KEY, en el .env). Esto sí: cada noche un volcado de TODA la base y el .env,
// cifrados con la clave de las copias (que el dueño guarda afuera), comprobados y subidos al mismo disco externo que las
// copias por empresa (Backblaze). Se restaura con herramientas estándar (openssl y pg_restore): ver docs/MANUAL-DATOS-Y-RESTAURACION.md.
class CopiaCompletaService
{
    public const DIR = 'copias-completas';
    public const ITER = 200000;   // vueltas de PBKDF2 al derivar la llave de la clave (openssl enc -pbkdf2 -iter)
    public const DIAS = 14;       // fuera del servidor: las de los últimos 14 días…
    public const MESES = 12;      // …y la primera de cada mes durante un año
    public const LOCALES = 2;     // en el servidor quedan sólo las 2 últimas (el disco no es el lugar)

    public static function clave(): ?string { return SistemaConfig::get('copia_clave') ?: null; }

    // Clave nueva: 6 grupos de 5 letras y números sin los que se confunden (0/O, 1/I). Se muestra una sola vez.
    public static function generarClave(): string
    {
        $alf = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $g = [];
        for ($i = 0; $i < 6; $i++) { $s = ''; for ($j = 0; $j < 5; $j++) $s .= $alf[random_int(0, strlen($alf) - 1)]; $g[] = $s; }
        $k = implode('-', $g);
        SistemaConfig::set('copia_clave', $k);
        SistemaConfig::set('copia_clave_en', now()->toDateTimeString());
        return $k;
    }

    public static function disco(): ?string { $d = config('services.backups.disk'); return $d && config("filesystems.disks.{$d}") ? $d : null; }

    public function crear(string $origen = 'auto'): CopiaCompleta
    {
        $clave = self::clave();
        if (! $clave) throw new \RuntimeException('Falta la clave de las copias completas: generala en Panel → Sistema → Copia completa de la base.');
        $t0 = microtime(true);
        $c = CopiaCompleta::create(['origen' => $origen, 'estado' => 'haciendo', 'motor' => DB::connection()->getDriverName()]);
        $dir = Storage::disk('local')->path(self::DIR); if (! is_dir($dir)) mkdir($dir, 0700, true);
        $base = 'erp-' . now()->format('Ymd-His') . "-{$c->id}"; $plano = "{$dir}/{$base}.dump"; $prueba = "{$dir}/{$base}.prueba";
        try {
            $tablas = $this->volcar($plano);
            $this->cifrar($plano, "{$plano}.enc", $clave);
            // La prueba es la de una restauración: se descifra con la clave y tiene que dar exactamente el volcado.
            $this->descifrar("{$plano}.enc", $prueba, $clave);
            if (hash_file('sha256', $prueba) !== hash_file('sha256', $plano)) throw new \RuntimeException('La copia cifrada no se pudo descifrar igual al original.');
            $env = base_path('.env'); $envRel = null;
            if (is_file($env)) { $this->cifrar($env, "{$dir}/{$base}.env.enc", $clave); $envRel = self::DIR . "/{$base}.env.enc"; }
            $c->fill(['archivo' => self::DIR . "/{$base}.dump.enc", 'env_archivo' => $envRel, 'bytes' => filesize("{$plano}.enc"), 'sha256' => hash_file('sha256', "{$plano}.enc"), 'tablas' => $tablas, 'verificada' => true]);
            [$c->remoto, $c->remoto_env] = $this->subir($c);
            $c->forceFill(['estado' => 'ok', 'segundos' => (int) round(microtime(true) - $t0)])->save();
        } catch (\Throwable $e) {
            $c->forceFill(['estado' => 'error', 'error' => mb_substr($e->getMessage(), 0, 1000), 'segundos' => (int) round(microtime(true) - $t0)])->save();
            throw $e;
        } finally {
            foreach ([$plano, $prueba] as $f) if (is_file($f)) @unlink($f); // el volcado sin cifrar no queda en ningún lado
        }
        $this->podar();
        return $c->fresh();
    }

    // El volcado de la base entera. Devuelve cuántas tablas con datos trae (si no trae ninguna, la copia no sirve).
    private function volcar(string $destino): int
    {
        $con = DB::connection(); $driver = $con->getDriverName();
        if ($driver === 'pgsql') {
            $cfg = $con->getConfig();
            $r = Process::timeout(3600)->env(['PGPASSWORD' => (string) ($cfg['password'] ?? '')])->run(['pg_dump', '-Fc', '--no-owner', '--no-privileges',
                '-h', (string) ($cfg['host'] ?? '127.0.0.1'), '-p', (string) ($cfg['port'] ?? 5432), '-U', (string) ($cfg['username'] ?? ''), '-f', $destino, (string) $cfg['database']]);
            if (! $r->successful()) throw new \RuntimeException('pg_dump falló: ' . trim($r->errorOutput() ?: $r->output()));
            $l = Process::timeout(600)->run(['pg_restore', '--list', $destino]);
            if (! $l->successful()) throw new \RuntimeException('El volcado no se puede leer (pg_restore --list): ' . trim($l->errorOutput()));
            $n = preg_match_all('/ TABLE DATA /', $l->output());
        } elseif ($driver === 'sqlite') {
            // Desarrollo y pruebas: otra base SQLite con el mismo esquema y las mismas filas (sirve también con la base en memoria
            // y adentro de una transacción, donde VACUUM INTO no se puede usar).
            $dst = new \PDO('sqlite:' . $destino); $dst->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); $dst->beginTransaction(); $n = 0;
            foreach ($con->select("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%' and sql is not null") as $t) {
                $dst->exec($t->sql); $n++;
                foreach ($con->table($t->name)->cursor() as $fila) {
                    $f = (array) $fila; $cols = array_keys($f);
                    $dst->prepare('insert into "' . $t->name . '" ("' . implode('","', $cols) . '") values (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($f));
                }
            }
            $dst->commit(); $dst = null;
        } else {
            throw new \RuntimeException("La copia completa todavía no sabe volcar una base {$driver}.");
        }
        if ($n < 1 || ! is_file($destino) || filesize($destino) === 0) throw new \RuntimeException('El volcado salió vacío.');
        return $n;
    }

    // AES-256 con la llave derivada de la clave (PBKDF2). Es el formato de «openssl enc»: se descifra en cualquier compu.
    private function cifrar(string $in, string $out, string $clave): void { $this->openssl(['-in', $in, '-out', $out], $clave); }
    private function descifrar(string $in, string $out, string $clave): void { $this->openssl(['-d', '-in', $in, '-out', $out], $clave); }

    private function openssl(array $args, string $clave): void
    {
        $r = Process::timeout(3600)->env(['COPIA_CLAVE' => $clave])->run(array_merge(['openssl', 'enc', '-aes-256-cbc', '-pbkdf2', '-iter', (string) self::ITER, '-salt'], $args, ['-pass', 'env:COPIA_CLAVE']));
        if (! $r->successful()) throw new \RuntimeException('openssl falló: ' . trim($r->errorOutput()));
    }

    // Fuera del servidor, en el mismo disco que las copias por empresa: <prefijo>completa/AAAA/MM/erp-….dump.enc
    private function subir(CopiaCompleta $c): array
    {
        $disk = self::disco(); if (! $disk) return [null, null];
        $carpeta = config('services.backups.prefijo', 'bigsysweb/erp/') . 'completa/' . $c->created_at->format('Y/m') . '/';
        $out = [];
        foreach ([$c->archivo, $c->env_archivo] as $rel) {
            if (! $rel) { $out[] = null; continue; }
            $fh = fopen(Storage::disk('local')->path($rel), 'r');
            try { if (! Storage::disk($disk)->put($carpeta . basename($rel), $fh)) throw new \RuntimeException("El disco {$disk} no aceptó la copia completa."); }
            finally { if (is_resource($fh)) fclose($fh); }
            $out[] = "{$disk}:{$carpeta}" . basename($rel);
        }
        return $out;
    }

    // En el servidor quedan las 2 últimas. Afuera, las de los últimos 14 días y la primera de cada mes durante un año.
    public function podar(): void
    {
        $ok = CopiaCompleta::where('estado', 'ok')->orderByDesc('id')->get();
        foreach ($ok->slice(self::LOCALES) as $c) {
            foreach ([$c->archivo, $c->env_archivo] as $rel) if ($rel && Storage::disk('local')->exists($rel)) Storage::disk('local')->delete($rel);
        }
        $primeraDelMes = $ok->groupBy(fn($c) => $c->created_at->format('Y-m'))->map(fn($g) => $g->sortBy(fn($c) => $c->created_at->timestamp)->first()->id)->values()->all();
        foreach ($ok as $c) {
            if ($c->podada || ! $c->remoto) continue;
            $queda = $c->created_at->gte(now()->subDays(self::DIAS)) || (in_array($c->id, $primeraDelMes, true) && $c->created_at->gte(now()->subMonths(self::MESES)));
            if ($queda) continue;
            foreach ([$c->remoto, $c->remoto_env] as $r) {
                if (! $r || ! str_contains($r, ':')) continue;
                [$disk, $ruta] = explode(':', $r, 2);
                try { if (config("filesystems.disks.{$disk}")) Storage::disk($disk)->delete($ruta); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning("Copia completa: no se pudo borrar {$r}: " . $e->getMessage()); }
            }
            $c->forceFill(['podada' => true])->save();
        }
    }

    // Para el panel y la salud.
    public static function estado(): array
    {
        $ult = CopiaCompleta::where('estado', 'ok')->latest('id')->first();
        $err = CopiaCompleta::latest('id')->first();
        return [
            'clave' => (bool) self::clave(), 'clave_en' => ($f = SistemaConfig::get('copia_clave_en')) ? \Carbon\Carbon::parse($f)->format('d/m/Y H:i') : null, 'disco' => self::disco(), 'motor' => DB::connection()->getDriverName(),
            'ultima' => $ult ? ['fecha' => $ult->created_at->format('d/m/Y H:i'), 'hace_horas' => (int) abs(now()->diffInHours($ult->created_at)), 'mb' => round($ult->bytes / 1048576, 2), 'tablas' => $ult->tablas, 'remoto' => (bool) $ult->remoto] : null,
            'fallo' => $err && $err->estado === 'error' ? ['fecha' => $err->created_at->format('d/m/Y H:i'), 'error' => $err->error] : null,
            'fuera' => CopiaCompleta::where('estado', 'ok')->whereNotNull('remoto')->where('podada', false)->count(),
            'lista' => CopiaCompleta::latest('id')->limit(6)->get()->map(fn($c) => ['id' => $c->id, 'fecha' => $c->created_at->format('d/m/Y H:i'), 'origen' => $c->origen, 'estado' => $c->estado, 'mb' => $c->bytes ? round($c->bytes / 1048576, 2) : null, 'tablas' => $c->tablas, 'remoto' => $c->remoto && ! $c->podada, 'local' => $c->archivo && Storage::disk('local')->exists($c->archivo), 'error' => $c->error ? mb_substr($c->error, 0, 200) : null])->all(),
        ];
    }
}
