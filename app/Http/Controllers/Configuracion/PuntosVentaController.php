<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PuntoVenta;
use App\Services\Comprobantes\AfipEmisor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PuntosVentaController extends Controller
{
    public function index(Request $request, AfipEmisor $afip)
    {
        $b = $request->user()->business;
        return Inertia::render('Configuracion/PuntosVenta', [
            'puntos' => PuntoVenta::with('location:id,name')->orderBy('numero')->get()->map(fn($p) => ['id' => $p->id, 'numero' => $p->numero, 'modo' => $p->modo, 'activo' => $p->activo, 'business_location_id' => $p->business_location_id, 'sucursal' => $p->location?->name]),
            'sucursales' => $b->locations()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'afip' => ['configurado' => $afip->configurado($b), 'cuit' => $b->cuit, 'produccion' => $b->afip_produccion, 'cert' => (bool) $b->afip_cert_path, 'key' => (bool) $b->afip_key_path],
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $b = $request->user()->business;
        $data = $request->validate([
            'numero' => ['required', 'integer', 'min:1', 'max:9999', Rule::unique('puntos_venta', 'numero')->where('business_id', $b->id)->ignore($id)],
            'modo' => 'required|in:electronico,manual', 'activo' => 'boolean',
            'business_location_id' => ['nullable', Rule::exists('business_locations', 'id')->where('business_id', $b->id)],
        ]);
        $p = $id ? PuntoVenta::findOrFail($id) : new PuntoVenta(['business_id' => $b->id]);
        $p->fill($data + ['activo' => $data['activo'] ?? true])->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $p, "Punto de venta {$p->numero}");
        return back()->with('success', 'Punto de venta guardado.');
    }

    public function certificados(Request $request)
    {
        $request->validate(['cert' => 'required|file|max:64', 'key' => 'required|file|max:64']);
        $b = $request->user()->business;
        $cert = file_get_contents($request->file('cert')->getRealPath()); $key = file_get_contents($request->file('key')->getRealPath());
        if (! str_contains($cert, '-----BEGIN') || ! str_contains($key, '-----BEGIN')) return back()->withErrors(['cert' => 'El certificado y la clave tienen que ser archivos PEM (empiezan con -----BEGIN).']);
        // Se guardan cifrados con la clave de la aplicación; el servicio de AFIP los descifra a un archivo temporal al usarlos.
        $b->update([
            'afip_cert_path' => \App\Services\Afip\CertificadoCifrado::guardar($b, 'cert.crt', $cert),
            'afip_key_path'  => \App\Services\Afip\CertificadoCifrado::guardar($b, 'private.key', $key),
        ]);
        AuditLog::registrar('editar', $b, 'Cargó certificados AFIP');
        return back()->with('success', 'Certificados AFIP cargados. Ya podés emitir comprobantes electrónicos.');
    }
}
