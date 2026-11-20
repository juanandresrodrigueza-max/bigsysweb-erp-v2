<?php

namespace App\Services\Suscripciones;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\SistemaConfig;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

// Fase 106: altas con aprobación, copiado del CRM (server/lib/aprobacionPrueba.ts) con 72 horas en vez de 24.
//
// Quien se registra solo, o la empresa que da de alta un empleado de BigSys desde el panel, entra al toque, pero la empresa
// y su dueño quedan «pendientes»: acceso provisional por N horas (Panel → Sistema, 72 por defecto). Si en ese plazo nadie la
// aprueba (Panel → Pendientes, con la clave de aprobación), se bloquea, también la sesión que ya estaba abierta. Al aprobarla
// se destraba en el acto. Avisos a BigSys: al registrarse, unas horas antes del corte y cuando se bloquea; por mail, por
// WhatsApp (desde la línea de la empresa de BigSys) y a los celulares de los superadmins (app).
class AprobacionService
{
    public const AVISO_ANTES_HORAS = 12;
    public const MENSAJE_ESPERANDO = 'Tu prueba gratis está esperando que la habilitemos. Te contactamos a la brevedad; si querés acelerarlo, escribinos.';

    public function horas(): int { return max(1, (int) SistemaConfig::get('horas_provisional')); }

    public function corte(Business $b): \Carbon\Carbon { return $b->created_at->copy()->addHours($this->horas()); }

    public function pendiente(Business $b): bool { return $b->aprobacion === 'pendiente'; }

    // Pendiente y con el plazo provisional vencido: no entra hasta que alguien la apruebe.
    public function vencida(Business $b): bool { return $this->pendiente($b) && $this->corte($b)->isPast(); }

    public function claveConfigurada(): bool { return (bool) SistemaConfig::get('clave_aprobacion'); }

    // ---- Quién administra la clave -----------------------------------------------------------------------------------------
    // La clave la cambian sólo sus «dueños»: quien la cargó primero y a quienes ese dueño designe. Siempre con la clave actual.
    // Si no queda nadie (o se perdió), se resetea desde el servidor: php artisan aprobacion:reset-clave.

    public function duenos(): array { return array_values(array_map('intval', (array) SistemaConfig::get('clave_aprobacion_duenos'))); }

    public function esDueno(User $u): bool { return $u->is_superadmin && in_array($u->id, $this->duenos(), true); }

    public function puedeCambiarClave(User $u): bool { return $u->is_superadmin && (! $this->claveConfigurada() ? true : $this->esDueno($u)); }

    // Devuelve el error, o null si la guardó. La primera carga no pide clave actual y convierte a quien la carga en dueño.
    public function guardarClave(string $clave, ?User $por = null, ?string $actual = null): ?string
    {
        if ($por && ! $this->puedeCambiarClave($por)) return 'La clave de aprobación la cambian sólo ' . $this->nombresDuenos() . '. Pedísela a ellos.';
        if ($this->claveConfigurada() && $por) {
            if (! $actual) return 'Para cambiarla, escribí también la clave actual.';
            if (! Hash::check($actual, (string) SistemaConfig::get('clave_aprobacion'))) {
                AuditLog::registrar('aprobacion_clave_incorrecta', null, "Clave actual incorrecta al intentar cambiar la clave de aprobación ({$por->email})");
                return 'La clave actual es incorrecta.';
            }
        }
        SistemaConfig::set('clave_aprobacion', Hash::make($clave));
        if ($por && ! $this->duenos()) SistemaConfig::set('clave_aprobacion_duenos', [$por->id]);
        return null;
    }

    // El dueño elige qué otros superadmins pueden cambiar la clave y designar. Él mismo queda siempre.
    public function designar(User $por, array $ids): ?string
    {
        if (! $this->esDueno($por)) return 'Sólo ' . $this->nombresDuenos() . ' pueden designar quién administra la clave.';
        $validos = User::withoutGlobalScopes()->where('is_superadmin', true)->where('status', 'active')->whereIn('id', array_map('intval', $ids))->pluck('id')->all();
        SistemaConfig::set('clave_aprobacion_duenos', array_values(array_unique(array_merge([$por->id], $validos))));
        AuditLog::registrar('editar', null, 'Administradores de la clave de aprobación: ' . $this->nombresDuenos());
        return null;
    }

    public function nombresDuenos(): string
    {
        $n = User::withoutGlobalScopes()->whereIn('id', $this->duenos())->pluck('name')->all();
        return $n ? implode(', ', $n) : 'nadie (hay que resetearla desde el servidor)';
    }

    public function resetearClave(): void { SistemaConfig::set('clave_aprobacion', null); SistemaConfig::set('clave_aprobacion_duenos', []); }

    // Marca la empresa y su dueño como pendientes. $pedidaPor es el superadmin que la dio de alta (null si se registró sola).
    public function marcarPendiente(Business $b, ?User $pedidaPor = null): void
    {
        $b->forceFill(['aprobacion' => 'pendiente', 'aprobacion_pedida_por' => $pedidaPor?->id, 'aprobada_en' => null, 'aprobada_por' => null, 'aprobacion_avisos' => []])->save();
        User::withoutGlobalScopes()->where('business_id', $b->id)->update(['aprobacion' => 'pendiente']);
    }

    // La clave de aprobación: 5 intentos fallidos por usuario cada 15 minutos; cada fallo queda en la auditoría (sin la clave).
    public function validarClave(User $quien, ?string $clave, Business $b, string $accion): ?string
    {
        if (! $this->claveConfigurada()) return 'Todavía no hay clave de aprobación. Cargala en Panel → Sistema → Altas con aprobación.';
        if (! $clave) return 'Ingresá la clave de aprobación.';
        $k = "aprobacion:{$quien->id}";
        if (RateLimiter::tooManyAttempts($k, 5)) return 'Demasiados intentos con la clave. Esperá ' . ceil(RateLimiter::availableIn($k) / 60) . ' minuto(s).';
        if (! Hash::check($clave, (string) SistemaConfig::get('clave_aprobacion'))) {
            RateLimiter::hit($k, 900);
            AuditLog::registrar('aprobacion_clave_incorrecta', $b, "Clave de aprobación incorrecta al intentar {$accion} ({$quien->email})");
            return 'La clave de aprobación es incorrecta.';
        }
        RateLimiter::clear($k);
        return null;
    }

    public function aprobar(Business $b, User $quien): void
    {
        $b->forceFill(['aprobacion' => 'aprobada', 'aprobada_en' => now(), 'aprobada_por' => $quien->id])->save();
        User::withoutGlobalScopes()->where('business_id', $b->id)->where('aprobacion', 'pendiente')->update(['aprobacion' => 'aprobada']);
        AuditLog::registrar('aprobacion_empresa', $b, "Empresa {$b->name} aprobada por {$quien->email}");
        if ($b->owner?->email) {
            try { Mail::to($b->owner->email)->send(new \App\Mail\AvisoAprobacion('Tu prueba de BigSys Web quedó habilitada', "Hola {$b->owner->name},\n\nTu prueba de BigSys Web para {$b->name} ya quedó habilitada. Entrá cuando quieras con tu email y contraseña.\n\nCualquier duda, escribinos.", url('/login'))); }
            catch (\Throwable $e) { Log::warning('No se pudo avisar la aprobación: ' . $e->getMessage()); }
        }
    }

    // Rechazar = la empresa queda suspendida con el motivo; nadie entra. Se puede reactivar desde la ficha.
    public function rechazar(Business $b, User $quien, string $motivo): void
    {
        $b->forceFill(['aprobacion' => 'rechazada', 'suspended_at' => now(), 'suspension_motivo' => 'Alta no aprobada: ' . $motivo])->save();
        User::withoutGlobalScopes()->where('business_id', $b->id)->where('aprobacion', 'pendiente')->update(['aprobacion' => 'rechazada']);
        AuditLog::registrar('rechazo_empresa', $b, "Alta de {$b->name} rechazada por {$quien->email}: {$motivo}");
    }

    // ---- Avisos a BigSys ------------------------------------------------------------------------------------------------

    public function destinatarios(): array
    {
        $cfg = collect(explode(',', (string) SistemaConfig::get('aviso_registros_emails')))->map(fn($e) => trim($e))->filter(fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($cfg->isNotEmpty()) return $cfg->unique()->values()->all();
        return collect([SistemaConfig::get('soporte_email')])->concat(User::withoutGlobalScopes()->where('is_superadmin', true)->where('status', 'active')->pluck('email'))->filter()->unique()->values()->all();
    }

    private function hora(\Carbon\Carbon $d): string { return $d->copy()->timezone(config('app.timezone', 'America/Argentina/Buenos_Aires'))->format('d/m H:i'); }

    private function ficha(Business $b): string
    {
        $d = $b->owner;
        return "{$b->name} (#{$b->id})\n" . ($d?->name ?? '—') . ' · ' . ($d?->email ?? '—') . ($d?->mobile ? " · {$d->mobile}" : '') . "\nRubro: " . (Business::VERTICALES[$b->vertical] ?? 'Otro')
            . ($b->aprobacion_pedida_por ? "\nDada de alta por: " . (User::withoutGlobalScopes()->find($b->aprobacion_pedida_por)?->email ?? '—') : "\nSe registró sola desde /registro");
    }

    // Mail + WhatsApp + celular de los superadmins. Nunca tira: el registro no espera esto.
    public function avisar(string $asunto, string $texto, string $url = '/admin/pendientes'): array
    {
        $ruta = $url; $url = url($url); $r = ['mail' => null, 'whatsapp' => null, 'celular' => null];
        // Fase 113.2: cada canal dice qué pasó (para el botón «Enviar aviso de prueba» y el log); el registro nunca espera esto.
        try {
            if (! ($to = $this->destinatarios())) $r['mail'] = ['ok' => false, 'texto' => 'No hay a quién mandarlo.'];
            else { SistemaConfig::aplicar(); Mail::to($to)->send(new \App\Mail\AvisoAprobacion($asunto, $texto, $url)); $r['mail'] = ['ok' => $this->mailConfigurado(), 'texto' => ($this->mailConfigurado() ? 'Mail a ' : 'Correo saliente sin configurar (bloque SMTP de esta pantalla): no llega. Iba a ') . implode(', ', $to) . '.']; }
        } catch (\Throwable $e) { Log::warning('Aviso de aprobación (mail): ' . $e->getMessage()); $r['mail'] = ['ok' => false, 'texto' => 'El mail falló: ' . mb_substr($e->getMessage(), 0, 160)]; }
        try {
            $nums = collect(explode(',', (string) SistemaConfig::get('aviso_registros_whatsapp')))->map(fn($n) => preg_replace('/\D/', '', $n))->filter()->unique();
            $bigsys = app(EmpresaBigSysService::class)->actual();
            if ($nums->isEmpty()) $r['whatsapp'] = ['ok' => false, 'texto' => 'Sin celulares cargados en «Avisar por WhatsApp a».'];
            elseif (! $bigsys) $r['whatsapp'] = ['ok' => false, 'texto' => 'No hay empresa de BigSys en el ERP (bloque «La empresa de BigSys» de esta pantalla): el WhatsApp sale por su línea.'];
            else {
                $wa = app(\App\Services\Canales\WhatsappPedidosService::class); $out = [];
                foreach ($nums as $n) { $x = $wa->responder($bigsys, $n, "*{$asunto}*\n{$texto}\n{$url}"); $out[] = $x['enviado'] ? "{$n} ✓" : "{$n}: " . ($x['error'] ?? 'no salió'); }
                $r['whatsapp'] = ['ok' => ! collect($out)->contains(fn($o) => ! str_ends_with($o, '✓')), 'texto' => implode(' · ', $out)];
            }
        } catch (\Throwable $e) { Log::warning('Aviso de aprobación (WhatsApp): ' . $e->getMessage()); $r['whatsapp'] = ['ok' => false, 'texto' => 'WhatsApp falló: ' . mb_substr($e->getMessage(), 0, 160)]; }
        try {
            $cel = app(\App\Services\Avisos\CelularService::class); $n = 0;
            foreach (User::withoutGlobalScopes()->where('is_superadmin', true)->where('status', 'active')->get() as $u) $n += (int) $cel->enviar($u, $asunto, \Illuminate\Support\Str::limit($texto, 160), $ruta); // el toque lleva a la pantalla del aviso
            $r['celular'] = ['ok' => $n > 0, 'texto' => $n ? "Aviso al celular en {$n} dispositivo(s) de superadmins." : 'Ningún superadmin tiene la app o el navegador suscripto a los avisos (Panel → Sistema → App del celular, o la campanita del navegador).'];
        } catch (\Throwable $e) { Log::warning('Aviso de aprobación (celular): ' . $e->getMessage()); $r['celular'] = ['ok' => false, 'texto' => 'Celular falló: ' . mb_substr($e->getMessage(), 0, 160)]; }
        return $r;
    }

    private function mailConfigurado(): bool { return config('mail.default') !== 'log' && (bool) (SistemaConfig::get('mail_host') ?: config('mail.mailers.smtp.host')); }

    // Estado de cada canal de aviso, para mostrarlo antes de que alguien se registre y no enterarse tarde.
    public function canales(): array
    {
        $bigsys = app(EmpresaBigSysService::class)->actual();
        $nums = collect(explode(',', (string) SistemaConfig::get('aviso_registros_whatsapp')))->map(fn($n) => preg_replace('/\D/', '', $n))->filter();
        $ids = User::withoutGlobalScopes()->where('is_superadmin', true)->where('status', 'active')->pluck('id');
        return [
            'mail' => ['ok' => $this->mailConfigurado(), 'texto' => $this->mailConfigurado() ? 'Mail a ' . implode(', ', $this->destinatarios()) : 'Correo saliente sin configurar (bloque SMTP de esta pantalla): el mail no sale.'],
            'whatsapp' => ['ok' => $nums->isNotEmpty() && $bigsys && ! empty($bigsys->whatsapp_settings['token']) && ! empty($bigsys->whatsapp_settings['phone_id']),
                'texto' => $nums->isEmpty() ? 'Sin celulares cargados.' : (! $bigsys ? 'Falta la empresa de BigSys en el ERP.' : (empty($bigsys->whatsapp_settings['token']) || empty($bigsys->whatsapp_settings['phone_id']) ? "La empresa {$bigsys->name} no tiene la API de WhatsApp Cloud conectada: entrá como dueño → Configuración → Tienda y canales → Pedidos por WhatsApp." : "Por la línea de {$bigsys->name} a " . $nums->implode(', ')))],
            'celular' => ['ok' => \App\Models\PushSuscripcion::whereIn('user_id', $ids)->exists(), 'texto' => \App\Models\PushSuscripcion::whereIn('user_id', $ids)->exists() ? 'Hay superadmins con la app o el navegador suscriptos.' : 'Ningún superadmin tiene avisos al celular activados todavía.'],
        ];
    }

    public function avisarAlta(Business $b): void
    {
        $corte = $this->hora($this->corte($b));
        $this->avisar("Alta nueva: {$b->name} · aprobar antes del {$corte}",
            "Nueva empresa en el ERP, ya está adentro con acceso provisional hasta el {$corte} ({$this->horas()} h). Si no la aprobás antes, se bloquea sola hasta que la apruebes.\n\n" . $this->ficha($b));
    }

    // Corre cada hora: segundo aviso a las que les quedan menos de 12 h, y aviso cuando una quedó bloqueada. Una sola vez cada uno.
    public function avisarPendientes(): array
    {
        $r = ['por_vencer' => 0, 'bloqueadas' => 0];
        foreach (Business::where('aprobacion', 'pendiente')->with('owner')->get() as $b) {
            $avisos = (array) ($b->aprobacion_avisos ?? []);
            $corte = $this->corte($b);
            if ($corte->isPast() && empty($avisos['bloqueada'])) {
                $this->avisar("{$b->name} quedó bloqueada: nadie aprobó el alta", "La prueba de {$b->name} cumplió las {$this->horas()} h sin aprobación y quedó bloqueada. Aprobala y entra al instante.\n\n" . $this->ficha($b));
                $avisos['bloqueada'] = now()->toDateTimeString(); $r['bloqueadas']++;
            } elseif (! $corte->isPast() && now()->diffInHours($corte, true) < self::AVISO_ANTES_HORAS && empty($avisos['por_vencer'])) {
                $this->avisar("{$b->name}: se bloquea a las {$this->hora($corte)} si no la aprobás", "La prueba de {$b->name} todavía no está aprobada y se bloquea el {$this->hora($corte)}.\n\n" . $this->ficha($b));
                $avisos['por_vencer'] = now()->toDateTimeString(); $r['por_vencer']++;
            } else continue;
            $b->forceFill(['aprobacion_avisos' => $avisos])->save();
        }
        return $r;
    }

    // Lo que la revisión diaria de suscripciones le cuenta a BigSys: pruebas que terminaron hoy y las que vencen pronto.
    public function avisarVencimientos(array $terminaron, array $porVencer): void
    {
        if (! $terminaron && ! $porVencer) return;
        $t = '';
        if ($terminaron) $t .= "Terminaron su prueba hoy y siguen en el plan Gratis:\n" . implode("\n", array_map(fn($x) => "- {$x}", $terminaron)) . "\n\n";
        if ($porVencer) $t .= "Vencen pronto:\n" . implode("\n", array_map(fn($x) => "- {$x}", $porVencer)) . "\n\n";
        $this->avisar('Pruebas y suscripciones: ' . count($terminaron) . ' terminaron hoy · ' . count($porVencer) . ' por vencer', trim($t) . "\n\nLlamalos antes de que se enfríen.", '/admin/empresas?estado=trial');
    }

    public function pendientes(): \Illuminate\Support\Collection
    {
        return Business::where('aprobacion', 'pendiente')->with(['owner:id,name,email,mobile,last_login_at', 'subscription.plan'])->withCount('users')->orderBy('created_at')->get()->map(function (Business $b) {
            $corte = $this->corte($b);
            return ['id' => $b->id, 'nombre' => $b->name, 'rubro' => Business::VERTICALES[$b->vertical] ?? '—', 'cuit' => $b->cuit, 'dueno' => $b->owner?->name, 'email' => $b->owner?->email, 'celular' => $b->owner?->mobile,
                'alta' => $b->created_at->format('d/m/Y H:i'), 'hace' => $b->created_at->diffForHumans(), 'corte' => $corte->format('d/m/Y H:i'), 'horas_restantes' => $corte->isPast() ? 0 : (int) ceil(now()->diffInMinutes($corte) / 60),
                'bloqueada' => $corte->isPast(), 'origen' => $b->aprobacion_pedida_por ? 'Alta desde el panel por ' . (User::withoutGlobalScopes()->find($b->aprobacion_pedida_por)?->name ?? '—') : 'Se registró sola',
                'plan' => $b->subscription?->plan?->name, 'ultimo_acceso' => $b->owner?->last_login_at?->diffForHumans(), 'usuarios' => $b->users_count];
        });
    }
}
