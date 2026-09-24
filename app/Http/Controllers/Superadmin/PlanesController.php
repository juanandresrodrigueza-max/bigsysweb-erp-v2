<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PlanesController extends Controller
{
    public function index()
    {
        return Inertia::render('Superadmin/Planes', [
            'planes' => Plan::withCount(['subscriptions as activas' => fn($q) => $q->whereIn('status', ['trial', 'active', 'grace'])])->orderBy('price_monthly')->get()->map(fn($p) => [
                'id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'description' => $p->description, 'price_monthly' => (float) $p->price_monthly, 'price_yearly' => (float) $p->price_yearly,
                'max_users' => $p->max_users, 'max_locations' => $p->max_locations, 'max_products' => $p->max_products, 'features' => (array) $p->features, 'is_active' => $p->is_active, 'is_free' => $p->is_free, 'activas' => $p->activas,
            ]),
            'modulos' => collect(config('erp.modulos'))->map(fn($m, $k) => ['key' => $k, 'label' => $m['label'], 'core' => $m['core']])->values(),
            'extras' => ['afip' => 'Facturación electrónica AFIP', 'crm' => 'CRM BigSys', 'mercadopago' => 'Cobros MercadoPago', 'tiendanube' => 'Tiendanube', 'api' => 'API', 'soporte_prioritario' => 'Soporte prioritario'],
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'name' => 'required|string|max:60', 'description' => 'nullable|string|max:200', 'price_monthly' => 'required|numeric|min:0', 'price_yearly' => 'required|numeric|min:0',
            'max_users' => 'required|integer|min:-1', 'max_locations' => 'required|integer|min:-1', 'max_products' => 'required|integer|min:-1',
            'features' => 'nullable|array', 'is_active' => 'boolean', 'is_free' => 'boolean',
        ]);
        $d['features'] = array_values($d['features'] ?? []);
        $plan = $id ? Plan::findOrFail($id) : new Plan(['slug' => Str::slug($d['name'])]);
        if (! $id && Plan::where('slug', $plan->slug)->exists()) $plan->slug .= '-' . Str::lower(Str::random(4));
        $plan->fill($d)->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $plan, "Plan {$plan->name}");
        return back()->with('success', "Plan {$plan->name} guardado.");
    }
}
