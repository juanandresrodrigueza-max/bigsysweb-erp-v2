<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\CotizacionConsultor;
use App\Models\Product;
use App\Services\Stock\ConsultorService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 32.4–32.5: el Consultor de precios (Stock → Consultor). Empleados, comprador, y las pantallas del salón para clientes.
class ConsultorController extends Controller
{
    public function __construct(private ConsultorService $svc) {}

    public function index(Request $request)
    {
        $u = $request->user();
        return Inertia::render('Stock/Consultor', [
            'veCostos' => $u->veCostos(), 'veProveedores' => $u->veProveedores(),
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->limit(400)->get(['id', 'name', 'lista_precios', 'mobile', 'phone']),
            'pantallas' => $this->svc->pantallas($u->business),
            'puedeConfigurar' => $u->puede('configuracion', 'editar'),
        ]);
    }

    public function buscar(Request $request)
    {
        $d = $request->validate(['q' => 'nullable|string|max:120', 'lista' => 'nullable|integer|min:1|max:6', 'contact_id' => 'nullable|integer']);
        $cli = ! empty($d['contact_id']) ? Contact::customers()->find($d['contact_id']) : null;
        return response()->json($this->svc->buscar($d['q'] ?? '', $request->user(), (int) ($d['lista'] ?? 1), $cli));
    }

    public function articulo(Request $request, int $id)
    {
        $d = $request->validate(['lista' => 'nullable|integer|min:1|max:6', 'contact_id' => 'nullable|integer']);
        $cli = ! empty($d['contact_id']) ? Contact::customers()->find($d['contact_id']) : null;
        return response()->json($this->svc->ficha(Product::findOrFail($id), $request->user(), (int) ($d['lista'] ?? 1), $cli));
    }

    public function cotizar(Request $request)
    {
        $d = $request->validate(['items' => 'required|array|min:1', 'items.*.id' => 'nullable|integer', 'items.*.nombre' => 'nullable|string|max:200', 'items.*.cantidad' => 'required|numeric|min:0.001', 'items.*.precio' => 'required|numeric|min:0',
            'contact_id' => 'nullable|integer', 'cliente' => 'nullable|string|max:150', 'telefono' => 'nullable|string|max:40', 'lista' => 'nullable|integer|min:1|max:6']);
        $c = $this->svc->cotizar($request->user(), $d);
        $tel = $c->telefono ? (strlen($c->telefono) <= 10 ? '549' . $c->telefono : $c->telefono) : null;
        return response()->json(['token' => $c->token, 'url' => url("/q/{$c->token}"), 'pdf' => url("/q/{$c->token}/pdf"), 'total' => (float) $c->total, 'texto' => $this->svc->textoWhatsapp($c), 'whatsapp' => $tel ? 'https://wa.me/' . $tel . '?text=' . rawurlencode($this->svc->textoWhatsapp($c)) : null]);
    }

    // Pantallas del salón (el Consultor en modo clientes): se crean acá y se abren por su llave de sólo lectura.
    public function crearPantalla(Request $request)
    {
        $d = $request->validate(['nombre' => 'nullable|string|max:60', 'lista' => 'nullable|integer|min:1|max:6']);
        $p = $this->svc->crearPantalla($request->user()->business, $d['nombre'] ?? 'Pantalla', (int) ($d['lista'] ?? 1));
        return response()->json(['id' => $p['id'], 'nombre' => $p['nombre'], 'lista' => $p['lista'], 'url' => url("/salon/{$p['token']}")]);
    }

    public function editarPantalla(Request $request, string $id)
    {
        $d = $request->validate(['nombre' => 'nullable|string|max:60', 'lista' => 'nullable|integer|min:1|max:6']);
        $this->svc->editarPantalla($request->user()->business, $id, $d);
        return back()->with('success', 'Pantalla actualizada: toma el cambio en su próximo refresco.');
    }

    public function bajaPantalla(Request $request, string $id)
    {
        $this->svc->bajaPantalla($request->user()->business, $id);
        return back()->with('success', 'Pantalla dada de baja: su llave ya no sirve.');
    }

    public function urlPantalla(Request $request, string $id)
    {
        $p = collect($this->svc->pantallas($request->user()->business))->firstWhere('id', $id);
        abort_unless($p, 404);
        return response()->json(['url' => url("/salon/{$p['token']}")]);
    }
}
