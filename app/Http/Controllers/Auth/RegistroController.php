<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use App\Services\Suscripciones\SuscripcionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

// Fase 46: "Registrate gratis". La empresa se da de alta sola con 15 días de Prueba (todo activado) y después sigue en Gratis.
class RegistroController extends Controller
{
    public const DIAS_PRUEBA = 15;

    public function show()
    {
        return Inertia::render('Auth/Registro', ['rubros' => Business::VERTICALES, 'marca' => Crypt::encryptString((string) now()->timestamp), 'dias' => self::DIAS_PRUEBA]);
    }

    public function store(Request $request, SuscripcionService $s)
    {
        // Contra robots: el campo trampa tiene que venir vacío y el formulario no se completa en menos de 3 segundos.
        try { $t = (int) Crypt::decryptString((string) $request->input('marca')); } catch (\Throwable) { $t = 0; }
        if (filled($request->input('sitio_web')) || ! $t || now()->timestamp - $t < 3) abort(422, 'No pudimos validar el formulario. Probá de nuevo.');

        $d = $request->validate([
            'empresa' => 'required|string|max:120', 'nombre' => 'required|string|max:120', 'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:8|confirmed', 'telefono' => 'nullable|string|max:40', 'rubro' => 'nullable|in:' . implode(',', array_keys(Business::VERTICALES)),
            'cuit' => ['nullable', 'string', 'max:20', fn($a, $v, $fail) => $v && ! \App\Support\Cuit::valido($v) ? $fail('El CUIT no es válido.') : null], 'acepto' => 'accepted',
        ], ['email.unique' => 'Ya hay una cuenta con ese email. Ingresá o recuperá la contraseña.', 'acepto.accepted' => 'Tenés que aceptar los términos.']);

        $plan = Plan::where('slug', 'prueba')->first() ?? Plan::orderByDesc('id')->firstOrFail();
        $b = $s->crearEmpresa(['name' => $d['empresa'], 'dueno_nombre' => $d['nombre'], 'dueno_email' => $d['email'], 'dueno_password' => $d['password'], 'dueno_mobile' => $d['telefono'] ?? null,
            'phone' => $d['telefono'] ?? null, 'cuit' => $d['cuit'] ?? null, 'vertical' => $d['rubro'] ?? 'otro', 'plan_id' => $plan->id, 'modo' => 'trial', 'dias_prueba' => self::DIAS_PRUEBA, 'notas_internas' => 'Alta desde Registrate gratis']);

        $this->avisarBigsys($b, $d);
        Auth::login(User::findOrFail($b->owner_id));
        $request->session()->regenerate();
        return redirect('/primeros-pasos')->with('success', '¡Listo! Tenés ' . self::DIAS_PRUEBA . ' días con todo activado. Empezá por estos pasos.');
    }

    private function avisarBigsys(Business $b, array $d): void
    {
        $a = collect([\App\Models\SistemaConfig::get('soporte_email')])->concat(User::withoutGlobalScopes()->where('is_superadmin', true)->pluck('email'))->filter()->unique()->all();
        if (! $a) return;
        try {
            Mail::raw("Nueva empresa registrada sola:\n\n{$b->name}\n{$d['nombre']} · {$d['email']}" . (! empty($d['telefono']) ? " · {$d['telefono']}" : '') . "\nRubro: " . (Business::VERTICALES[$d['rubro'] ?? 'otro'] ?? 'Otro') . "\nPrueba de " . self::DIAS_PRUEBA . ' días.', fn($m) => $m->to($a)->subject("Nuevo registro: {$b->name}"));
        } catch (\Throwable $e) { Log::warning('No se pudo avisar el registro: ' . $e->getMessage()); }
    }
}
