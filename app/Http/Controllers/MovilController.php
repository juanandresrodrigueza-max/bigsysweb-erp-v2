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

    // Deep links: con estos dos archivos, un link a erp.bigsysweb.com abre la app si está instalada (Android verifica la huella
    // SHA-256 de la firma; iPhone el Team ID de Apple). Los dos datos los carga el superadmin en Sistema → App del celular.
    public function assetlinks()
    {
        $huellas = collect(preg_split('/[\s,;]+/', (string) SistemaConfig::get('android_sha256')))->map(fn($h) => strtoupper(trim($h)))->filter(fn($h) => preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $h))->values()->all();
        $j = $huellas ? [['relation' => ['delegate_permission/common.handle_all_urls'], 'target' => ['namespace' => 'android_app', 'package_name' => 'ar.com.bigsys.erp', 'sha256_cert_fingerprints' => $huellas]]] : [];
        return response()->json($j)->header('Cache-Control', 'public, max-age=3600');
    }

    public function appleAppSiteAssociation()
    {
        $team = strtoupper(trim((string) SistemaConfig::get('apple_team_id')));
        $j = preg_match('/^[A-Z0-9]{10}$/', $team) ? ['applinks' => ['details' => [['appIDs' => ["{$team}.ar.com.bigsys.erp"], 'components' => [['/' => '/*']]]]], 'webcredentials' => ['apps' => ["{$team}.ar.com.bigsys.erp"]]] : ['applinks' => ['details' => []]];
        return response()->json($j)->header('Content-Type', 'application/json')->header('Cache-Control', 'public, max-age=3600');
    }

    // Links a las tiendas (los carga el superadmin) para mostrarlos en Avisos al celular y en la ayuda.
    public static function tiendas(): array
    {
        return ['android' => SistemaConfig::get('app_android_url') ?: null, 'ios' => SistemaConfig::get('app_ios_url') ?: null];
    }
}
