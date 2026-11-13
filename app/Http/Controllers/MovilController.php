<?php

namespace App\Http\Controllers;

use App\Models\SistemaConfig;
use App\Services\Avisos\PushNativoService;
use Illuminate\Http\Request;

// Fase 93: lo que la app nativa (Capacitor) le pide al servidor: registrar el celular para los avisos y darse de baja.
class MovilController extends Controller
{
    public function dispositivo(Request $r, PushNativoService $svc)
    {
        $d = $r->validate(['token' => 'required|string|min:20|max:300', 'plataforma' => 'required|in:ios,android', 'modelo' => 'nullable|string|max:120', 'version_app' => 'nullable|string|max:30']);
        $disp = $svc->registrar($r->user(), $d);
        return response()->json(['ok' => true, 'id' => $disp->id, 'push' => $svc->configurado()]);
    }

    public function baja(Request $r, PushNativoService $svc)
    {
        $d = $r->validate(['token' => 'nullable|string|max:300']);
        return response()->json(['ok' => true, 'borrados' => $svc->baja($r->user(), $d['token'] ?? null)]);
    }

    // Links a las tiendas (los carga el superadmin) para mostrarlos en Avisos al celular y en la ayuda.
    public static function tiendas(): array
    {
        return ['android' => SistemaConfig::get('app_android_url') ?: null, 'ios' => SistemaConfig::get('app_ios_url') ?: null];
    }
}
