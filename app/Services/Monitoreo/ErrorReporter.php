<?php

namespace App\Services\Monitoreo;

use App\Models\ErrorSistema;
use App\Models\SistemaConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// Monitoreo de errores: cada excepción real se agrupa en la base (panel Salud) y, si hay DSN cargado, se manda a Sentry
// o a cualquier servicio compatible (GlitchTip, Bugsink, self-hosted) por el protocolo "store" sin depender de un SDK.
class ErrorReporter
{
    // Lo que no es un error del sistema: validaciones, 404, sin permiso, sesión vencida.
    private const IGNORAR = [
        \Illuminate\Validation\ValidationException::class, \Illuminate\Auth\AuthenticationException::class, \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Session\TokenMismatchException::class, \Illuminate\Database\Eloquent\ModelNotFoundException::class, \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
    ];

    public static function ignorable(Throwable $e): bool
    {
        foreach (self::IGNORAR as $c) if ($e instanceof $c) return true;
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $e->getStatusCode() < 500) return true;
        return false;
    }

    public function reportar(Throwable $e): void
    {
        if (self::ignorable($e)) return;
        try { $this->guardarLocal($e); } catch (Throwable $x) { Log::warning('Monitoreo: no se pudo guardar el error: ' . $x->getMessage()); }
        try { $this->enviarSentry($e); } catch (Throwable $x) { Log::warning('Monitoreo: no se pudo enviar a Sentry: ' . $x->getMessage()); }
    }

    private function guardarLocal(Throwable $e): void
    {
        $req = app()->bound('request') ? request() : null;
        $hash = sha1(get_class($e) . '|' . $e->getFile() . '|' . $e->getLine());
        $row = ErrorSistema::where('hash', $hash)->first();
        $datos = ['clase' => get_class($e), 'mensaje' => Str::limit($e->getMessage(), 2000), 'archivo' => Str::after($e->getFile(), base_path() . '/'), 'linea' => $e->getLine(),
            'ruta' => $req ? Str::limit($req->method() . ' ' . $req->path(), 250) : (app()->runningInConsole() ? 'consola' : null), 'metodo' => $req?->method(),
            'business_id' => Auth::user()?->business_id, 'user_id' => Auth::id(), 'ultima_vez' => now(), 'traza' => Str::limit($e->getTraceAsString(), 6000)];
        if ($row) $row->forceFill($datos + ['veces' => $row->veces + 1, 'resuelto_en' => null])->save();
        else ErrorSistema::create($datos + ['hash' => $hash, 'primera_vez' => now(), 'veces' => 1]);
    }

    // DSN: https://CLAVE@host/PROYECTO → POST https://host/api/PROYECTO/store/
    public static function dsn(): ?array
    {
        $dsn = SistemaConfig::get('sentry_dsn') ?: config('services.sentry.dsn');
        if (! $dsn || ! ($u = parse_url($dsn)) || empty($u['user']) || empty($u['host']) || empty($u['path'])) return null;
        return ['clave' => $u['user'], 'url' => ($u['scheme'] ?? 'https') . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') . '/api/' . trim($u['path'], '/') . '/store/', 'proyecto' => trim($u['path'], '/')];
    }

    public function enviarSentry(Throwable $e, ?string $mensajePrueba = null): bool
    {
        if (! ($d = self::dsn())) return false;
        $req = app()->bound('request') ? request() : null;
        $frames = [];
        foreach (array_reverse(array_slice($e->getTrace(), 0, 30)) as $f) $frames[] = ['filename' => isset($f['file']) ? Str::after($f['file'], base_path() . '/') : '?', 'function' => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''), 'lineno' => $f['line'] ?? 0, 'in_app' => isset($f['file']) && ! str_contains($f['file'], '/vendor/')];
        $frames[] = ['filename' => Str::after($e->getFile(), base_path() . '/'), 'function' => '', 'lineno' => $e->getLine(), 'in_app' => true];
        $evento = [
            'event_id' => str_replace('-', '', (string) Str::uuid()), 'timestamp' => now()->toIso8601String(), 'platform' => 'php', 'level' => 'error', 'logger' => 'bigsysweb',
            'environment' => app()->environment(), 'release' => 'bigsysweb@' . (config('app.version') ?: '2.0'), 'server_name' => gethostname() ?: 'servidor',
            'exception' => ['values' => [['type' => get_class($e), 'value' => $mensajePrueba ?? Str::limit($e->getMessage(), 1000), 'stacktrace' => ['frames' => $frames]]]],
            'tags' => ['empresa' => (string) (Auth::user()?->business_id ?? ''), 'php' => PHP_VERSION, 'consola' => app()->runningInConsole() ? 'si' : 'no'],
            'user' => Auth::id() ? ['id' => (string) Auth::id()] : null,
            'request' => $req ? ['url' => $req->fullUrl(), 'method' => $req->method()] : null,
            'sdk' => ['name' => 'bigsysweb.store', 'version' => '1.0'],
        ];
        $r = Http::timeout(5)->withHeaders(['X-Sentry-Auth' => "Sentry sentry_version=7, sentry_client=bigsysweb/1.0, sentry_key={$d['clave']}", 'Content-Type' => 'application/json'])->post($d['url'], array_filter($evento, fn($v) => $v !== null));
        if ($r->successful()) { ErrorSistema::where('hash', sha1(get_class($e) . '|' . $e->getFile() . '|' . $e->getLine()))->update(['enviado_sentry' => true]); return true; }
        Log::warning('Monitoreo: Sentry respondió ' . $r->status());
        return false;
    }
}
