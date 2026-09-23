<?php

namespace App\Http\Controllers\Integraciones;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Integraciones\CrmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Pasar de un sistema al otro ya logueado: el ERP firma un token y el CRM lo canjea, y al revés.
class CrmHandoffController extends Controller
{
    // Botón "CRM" del menú: manda al CRM con un token de 60 segundos.
    public function ir(Request $request)
    {
        $user = $request->user(); $b = $user->business;
        abort_unless(CrmService::activo($b), 404, 'La integración con el CRM no está activa. Configurala en Configuración → CRM.');
        $destino = $request->query('a');
        $destino = is_string($destino) && str_starts_with($destino, '/') ? $destino : null;
        AuditLog::registrar('ver', null, 'Pasó al CRM' . ($destino ? " ({$destino})" : ''));
        return redirect()->away(CrmService::url($b, '/api/erp/sso?t=' . urlencode(CrmService::firmar($b, $user, $destino))));
    }

    // Entrada desde el CRM: valida el token, busca la empresa por CUIT y el usuario por email, y abre sesión.
    public function entrar(Request $request)
    {
        $token = (string) $request->query('t', '');
        $p = $token !== '' ? json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true) : null;
        $b = is_array($p) && ! empty($p['cuit']) ? CrmService::empresaPorCuit((string) $p['cuit']) : null;
        if (! $b || ! CrmService::activo($b)) return $this->rechazar('No hay una empresa del ERP con ese CUIT con la integración activa. Revisá Configuración → CRM en el ERP y el CUIT en el CRM.');
        $v = CrmService::verificar($token, CrmService::config($b)['secreto']);
        if (! $v['ok']) return $this->rechazar($v['motivo']);
        $p = $v['payload'];
        if (($p['origen'] ?? '') !== 'crm') return $this->rechazar('El token no viene del CRM.');
        $user = User::withoutGlobalScopes()->where('business_id', $b->id)->where('email', strtolower((string) ($p['email'] ?? '')))->where('status', 'active')->first();
        if (! $user) return $this->rechazar('Tu usuario (' . e((string) ($p['email'] ?? '')) . ') no existe en el ERP de ' . e($b->name) . '. Pedile al dueño que te dé de alta en Configuración → Usuarios: el ERP es el sistema madre de los usuarios.');
        if (Auth::check() && Auth::id() !== $user->id) Auth::logout();
        if (! Auth::check()) { Auth::login($user); $request->session()->regenerate(); }
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::registrar('login', $user, 'Entró desde el CRM');
        $destino = $p['a'] ?? null;
        return redirect(is_string($destino) && str_starts_with($destino, '/') ? $destino : '/dashboard')->with('success', 'Entraste desde el CRM.');
    }

    private function rechazar(string $motivo)
    {
        return response()->view('integraciones.crm_rechazado', ['motivo' => $motivo], 403);
    }
}
