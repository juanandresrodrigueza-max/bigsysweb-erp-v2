<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Claves de IA y correo cargadas desde el panel superadmin (tienen prioridad sobre el .env).
        if (! app()->runningUnitTests() || env('SISTEMA_CONFIG_APLICAR')) \App\Models\SistemaConfig::aplicar();
        // Límites de intentos (Fase 17 · Seguridad). El login además bloquea por email + IP desde el controlador.
        RateLimiter::for('login', fn(Request $r) => Limit::perMinute(10)->by(mb_strtolower((string) $r->input('email')) . '|' . $r->ip())->response(fn() => back()->withErrors(['email' => 'Demasiados intentos. Esperá un minuto y probá de nuevo.'])));
        RateLimiter::for('api-auth', fn(Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('publico', fn(Request $r) => Limit::perMinute(60)->by($r->ip()));       // páginas públicas: presupuestos, tienda, portal, órdenes
        RateLimiter::for('webhooks', fn(Request $r) => Limit::perMinute(300)->by($r->ip()));
        RateLimiter::for('api', fn(Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));

        // Recuperación de contraseña: link a la pantalla propia y mail en castellano.
        ResetPassword::createUrlUsing(fn($user, string $token) => url("/restablecer/{$token}?email=" . urlencode($user->email)));
        // Ojo: el segundo parámetro es el token, no la URL.
        ResetPassword::toMailUsing(fn($notifiable, string $token) => (new MailMessage())
            ->subject('Restablecer tu contraseña de BigSysWeb')
            ->greeting('Hola ' . ($notifiable->name ?? '') . ',')
            ->line('Recibimos un pedido para restablecer la contraseña de tu usuario.')
            ->action('Elegir una contraseña nueva', url("/restablecer/{$token}?email=" . urlencode($notifiable->email)))
            ->line('El link sirve durante ' . config('auth.passwords.users.expire', 60) . ' minutos. Si no fuiste vos, ignorá este mail: tu contraseña sigue igual.')
            ->salutation('BigSys'));
    }
}
