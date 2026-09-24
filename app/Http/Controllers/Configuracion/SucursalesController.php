<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BusinessLocation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SucursalesController extends Controller
{
    public function index(Request $request)
    {
        $b = $request->user()->business;
        $max = $b->activeSubscription?->plan?->max_locations ?? 1;

        return Inertia::render('Configuracion/Sucursales', [
            'items' => $b->locations()->withCount('users')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn($l) => $l->only('id', 'name', 'short_name', 'address', 'city', 'province', 'phone', 'email', 'is_active', 'is_default', 'cuit', 'razon_social', 'condicion_iva', 'iibb') + ['inicio_actividades' => $l->inicio_actividades?->toDateString(), 'afip_produccion' => (bool) $l->afip_produccion, 'cert' => (bool) $l->afip_cert_path, 'key' => (bool) $l->afip_key_path, 'puntos_venta' => $l->puntosVenta()->where('activo', true)->orderBy('numero')->pluck('numero'), 'usuarios' => $l->users_count]),
            'empresa' => ['cuit' => $b->cuit, 'razon_social' => $b->razon_social, 'condicion_iva' => $b->condicion_iva], 'condicionesIva' => ['Responsable Inscripto', 'Monotributista', 'Exento', 'No Responsable'],
            'limite' => ['max' => $max, 'usadas' => $b->locations()->count()],
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $b = $request->user()->business;
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'short_name'  => 'nullable|string|max:20',
            'address'     => 'nullable|string|max:255',
            'city'        => 'nullable|string|max:100',
            'province'    => 'nullable|string|max:100',
            'phone'       => 'nullable|string|max:50',
            'email'       => 'nullable|email|max:255',
            'is_active'   => 'boolean',
            'is_default'  => 'boolean',
            // Facturación propia: la sucursal emite con su CUIT (otra razón social o la misma con otro punto de venta y certificado).
            'cuit'          => ['nullable', 'string', 'max:20', 'regex:/^\d{2}-?\d{8}-?\d$/'],
            'razon_social'  => 'nullable|string|max:160', 'condicion_iva' => 'nullable|string|max:40', 'iibb' => 'nullable|string|max:40', 'inicio_actividades' => 'nullable|date', 'afip_produccion' => 'nullable|boolean',
        ], ['cuit.regex' => 'El CUIT tiene que tener 11 dígitos (con o sin guiones).']);
        if (empty($data['cuit'])) { $data['cuit'] = null; $data['razon_social'] = null; $data['condicion_iva'] = null; $data['iibb'] = null; $data['inicio_actividades'] = null; $data['afip_produccion'] = null; }
        elseif (empty($data['razon_social'])) return back()->withErrors(['razon_social' => 'Si la sucursal factura con su propio CUIT, cargá su razón social.']);

        if (! $id) {
            $max = $b->activeSubscription?->plan?->max_locations ?? 1;
            if ($max > 0 && $b->locations()->count() >= $max) {
                return back()->with('error', "Tu plan permite hasta {$max} sucursal(es). Cambiá de plan para agregar más.");
            }
        }

        $sucursal = $id ? $b->locations()->findOrFail($id) : new BusinessLocation(['business_id' => $b->id]);
        $antes = $sucursal->exists ? $sucursal->only(array_keys($data)) : null;
        $sucursal->fill($data)->save();
        if (! $id) {
            \App\Models\Deposito::porDefecto($sucursal->id); // crea el depósito principal de la sucursal nueva
        }

        if ($data['is_default'] ?? false) {
            $b->locations()->where('id', '!=', $sucursal->id)->update(['is_default' => false]);
        }

        AuditLog::registrar($id ? 'editar' : 'crear', $sucursal, "Sucursal {$sucursal->name}", $antes, $data);
        return back()->with('success', $id ? 'Sucursal actualizada.' : 'Sucursal creada.');
    }

    // Certificado y clave ARCA propios de la sucursal (mismo formato que los de la empresa, cifrados en disco).
    public function certificados(Request $request, int $id)
    {
        $request->validate(['cert' => 'required|file|max:64', 'key' => 'required|file|max:64']);
        $b = $request->user()->business;
        $s = $b->locations()->findOrFail($id);
        abort_if(! $s->cuit, 422, 'Primero cargá el CUIT propio de la sucursal.');
        $cert = file_get_contents($request->file('cert')->getRealPath()); $key = file_get_contents($request->file('key')->getRealPath());
        if (! str_contains($cert, '-----BEGIN') || ! str_contains($key, '-----BEGIN')) return back()->withErrors(['cert' => 'El certificado y la clave tienen que ser archivos PEM (empiezan con -----BEGIN).']);
        $s->update(['afip_cert_path' => \App\Services\Afip\CertificadoCifrado::guardar($b, 'cert.crt', $cert, $s->id), 'afip_key_path' => \App\Services\Afip\CertificadoCifrado::guardar($b, 'private.key', $key, $s->id)]);
        AuditLog::registrar('editar', $s, "Cargó certificados ARCA de la sucursal {$s->name} (CUIT {$s->cuit})");
        return back()->with('success', "Certificados ARCA de {$s->name} cargados. La sucursal ya factura con su CUIT {$s->cuit}.");
    }
}
