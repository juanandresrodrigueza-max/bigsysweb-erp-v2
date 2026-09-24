<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Canal;
use App\Models\Product;
use App\Services\Canales\CanalesService;
use App\Services\Canales\TiendaService;
use App\Services\Ventas\FidelizacionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Configuración de canales: tienda propia, menú QR y reservas, marketplaces/delivery, WhatsApp y programa de puntos.
class TiendaController extends Controller
{
    public function index(Request $request, TiendaService $tienda, FidelizacionService $fid)
    {
        $b = $request->user()->business;
        $cfg = $tienda->config($b);
        return Inertia::render('Configuracion/Tienda', [
            'config' => $cfg, 'urlTienda' => url('/t/' . $cfg['slug']), 'urlMenu' => url('/m/' . $cfg['slug']), 'urlReservas' => url('/r/' . $cfg['slug']),
            'rubros' => $tienda->rubrosDisponibles($b), 'enTienda' => Product::where('active', true)->where('en_tienda', true)->count(), 'totalArticulos' => Product::where('active', true)->whereIn('tipo', ['producto', 'elaborado', 'servicio'])->count(),
            'fidelizacion' => $fid->config($b),
            'canales' => Canal::orderBy('nombre')->get()->map(fn($c) => ['id' => $c->id, 'tipo' => $c->tipo, 'tipo_label' => Canal::TIPOS[$c->tipo]['label'] ?? $c->tipo, 'nombre' => $c->nombre, 'credenciales' => $c->credenciales, 'activo' => $c->activo, 'sync_stock' => $c->sync_stock, 'sync_precios' => $c->sync_precios, 'importar_pedidos' => $c->importar_pedidos, 'url_entrada' => $c->urlEntrada(), 'ultimo_sync' => $c->ultimo_sync_en?->diffForHumans(), 'ultimo_error' => $c->ultimo_error, 'pedidos_importados' => $c->pedidos_importados]),
            'tiposCanal' => Canal::TIPOS,
            'whatsapp' => ['configurado' => ! empty($b->whatsapp_settings['token']) && ! empty($b->whatsapp_settings['phone_id']), 'phone_id' => $b->whatsapp_settings['phone_id'] ?? null, 'url_entrada' => url('/api/whatsapp/entrante/' . $b->id), 'verify_token' => $b->whatsapp_settings['verify_token'] ?? null, 'ia' => (bool) config('services.anthropic.api_key')],
        ]);
    }

    public function guardar(Request $request)
    {
        $d = $request->validate(['tienda' => 'required|array', 'tienda.slug' => 'required|alpha_dash|min:3|max:40']);
        $b = $request->user()->business;
        $otro = TiendaService::porSlug($d['tienda']['slug']);
        if ($otro && $otro->id !== $b->id) return back()->withErrors(['tienda.slug' => 'Ese nombre de tienda ya está usado por otra empresa.']);
        $nuevo = array_intersect_key($request->input('tienda', []), TiendaService::DEFAULT); // validate() recorta el array anidado: se toma el input completo
        $b->update(['tienda' => array_replace(TiendaService::DEFAULT, $b->tienda ?? [], $nuevo)]);
        AuditLog::registrar('editar', $b, 'Configuró la tienda / canales');
        return back()->with('success', 'Configuración guardada.');
    }

    public function guardarFidelizacion(Request $request, FidelizacionService $fid)
    {
        $d = $request->validate(['activo' => 'boolean', 'pesos_por_punto' => 'required|numeric|min:1', 'valor_punto' => 'required|numeric|min:0', 'minimo_canje' => 'required|numeric|min:0', 'bienvenida' => 'nullable|numeric|min:0', 'excluir_cf' => 'boolean']);
        $b = $request->user()->business;
        $b->update(['fidelizacion' => array_replace(FidelizacionService::DEFAULT, $d)]);
        AuditLog::registrar('editar', $b, 'Configuró el programa de puntos');
        return back()->with('success', 'Programa de puntos guardado.');
    }

    public function articulos(Request $request)
    {
        $d = $request->validate(['ids' => 'required|array', 'en_tienda' => 'required|boolean']);
        $n = Product::whereIn('id', $d['ids'])->update(['en_tienda' => $d['en_tienda']]);
        return back()->with('success', "{$n} artículos " . ($d['en_tienda'] ? 'publicados' : 'ocultos') . ' en la tienda.');
    }

    public function guardarWhatsapp(Request $request)
    {
        $d = $request->validate(['token' => 'nullable|string|max:400', 'phone_id' => 'nullable|string|max:60', 'verify_token' => 'nullable|string|max:60', 'auto_pedidos' => 'boolean', 'auto_responder' => 'boolean']);
        $b = $request->user()->business;
        $ws = $b->whatsapp_settings ?? [];
        if (empty($d['token'])) unset($d['token']);
        $b->update(['whatsapp_settings' => array_merge($ws, $d, ['verify_token' => $d['verify_token'] ?: ($ws['verify_token'] ?? \Illuminate\Support\Str::random(16))])]);
        return back()->with('success', 'WhatsApp configurado.');
    }

    public function guardarCanal(Request $request, ?int $id = null)
    {
        $d = $request->validate(['tipo' => 'required|in:' . implode(',', array_keys(Canal::TIPOS)), 'nombre' => 'required|string|max:80', 'credenciales' => 'nullable|array', 'activo' => 'boolean', 'sync_stock' => 'boolean', 'sync_precios' => 'boolean', 'importar_pedidos' => 'boolean']);
        $c = $id ? Canal::findOrFail($id) : new Canal(['business_id' => $request->user()->business_id]);
        $cred = array_filter($d['credenciales'] ?? [], fn($v) => $v !== null && $v !== '');
        $c->fill(['tipo' => $d['tipo'], 'nombre' => $d['nombre'], 'credenciales' => array_merge($c->credenciales ?? [], $cred), 'activo' => $d['activo'] ?? true, 'sync_stock' => $d['sync_stock'] ?? true, 'sync_precios' => $d['sync_precios'] ?? false, 'importar_pedidos' => $d['importar_pedidos'] ?? true])->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $c, "Canal {$c->nombre}");
        return back()->with('success', 'Canal guardado.');
    }

    public function borrarCanal(int $id) { Canal::findOrFail($id)->delete(); return back()->with('success', 'Canal eliminado.'); }

    public function sincronizar(int $id, CanalesService $svc)
    {
        $c = Canal::findOrFail($id);
        $r = $svc->importarPedidos($c);
        return $r['error'] ? back()->with('error', "No se pudo sincronizar {$c->nombre}: {$r['error']}") : back()->with('success', "{$c->nombre}: {$r['importados']} pedidos nuevos.");
    }
}
