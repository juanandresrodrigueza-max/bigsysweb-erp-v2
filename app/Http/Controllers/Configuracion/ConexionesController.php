<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Canal;
use App\Models\Contact;
use App\Services\Canales\ConexionesService;
use Illuminate\Http\Request;

// Fase 30.1: conectar tiendas con un botón (OAuth) o con claves probadas antes de guardar; ajustes de cada canal; probar y desconectar.
class ConexionesController extends Controller
{
    public function __construct(private ConexionesService $cx) {}

    // Manda al usuario a autorizar en la tienda.
    public function ir(Request $request, string $tipo)
    {
        // Fase 32.3: Mercado Pago (cobros en el local) también se conecta con un botón; guarda en la empresa, no en un canal.
        if ($tipo === 'mercadopago') return redirect()->away(app(\App\Services\MercadoPago\MercadoPagoCobrosService::class)->urlOauth($request->user()->business));
        abort_unless(isset(Canal::TIPOS[$tipo]) && ! empty(Canal::TIPOS[$tipo]['oauth']), 404);
        $url = $this->cx->urlAutorizar($tipo, $request->user()->business, $request->only('url'));
        if ($tipo === 'woocommerce') { parse_str(parse_url($url, PHP_URL_QUERY), $q); $this->cx->recordarUrlWoo($q['user_id'], $request->input('url')); }
        return redirect()->away($url);
    }

    // La tienda trae al usuario de vuelta con el código (o las claves ya mandadas).
    public function volver(Request $request, string $tipo)
    {
        if ($tipo === 'mercadopago') {
            try { $b = $this->cx->empresaDelEstado($request->query('state'), 'mercadopago'); $s = app(\App\Services\MercadoPago\MercadoPagoCobrosService::class)->volverOauth($b, (string) $request->query('code')); }
            catch (\Illuminate\Validation\ValidationException $e) { return redirect('/configuracion#mercadopago')->with('error', collect($e->errors())->flatten()->first()); }
            return redirect('/configuracion#mercadopago')->with('success', 'Mercado Pago conectado' . (! empty($s['nickname']) ? " como {$s['nickname']}" : '') . '. El token se renueva solo.');
        }
        try { $c = $this->cx->volver($tipo, $request); }
        catch (\Illuminate\Validation\ValidationException $e) { return redirect('/configuracion/tienda#canales')->with('error', collect($e->errors())->flatten()->first()); }
        return redirect('/configuracion/tienda#canales')->with('success', "{$c->nombre} conectada." . ($c->ultimo_error ? ' ' . $c->ultimo_error : ''));
    }

    public function conectarConClaves(Request $request)
    {
        $d = $request->validate(['tipo' => 'required|in:' . implode(',', array_keys(Canal::TIPOS)), 'credenciales' => 'nullable|array']);
        $c = $this->cx->conectarConClaves($request->user()->business, $d['tipo'], $d['credenciales'] ?? []);
        return back()->with('success', "{$c->nombre} conectada." . ($c->ultimo_error ? ' ' . $c->ultimo_error : ''));
    }

    public function probar(int $id)
    {
        $c = Canal::findOrFail($id);
        $r = $this->cx->probar($c);
        if ($r['ok'] && $c->tienda() && $c->tipo !== 'mercadolibre' && empty($c->webhooks)) $this->cx->registrarWebhooks($c);
        return $r['ok'] ? back()->with('success', "{$c->nombre}: la conexión anda" . ($r['nombre'] ? " ({$r['nombre']})" : '') . '.') : back()->with('error', "{$c->nombre}: {$r['mensaje']}");
    }

    public function desconectar(int $id)
    {
        $c = Canal::findOrFail($id);
        $this->cx->desconectar($c);
        return back()->with('success', "{$c->nombre} desconectada. Lo publicado queda; los ajustes también.");
    }

    // Los ajustes de la tienda: a qué cuenta van las ventas, colchón, lista, quién factura, fecha de corte, modo automático, recargo, qué campos se mandan.
    public function configurar(Request $request, int $id)
    {
        $c = Canal::findOrFail($id);
        $d = $request->validate(['contact_id' => 'nullable|exists:contacts,id', 'colchon' => 'nullable|integer|min:0', 'lista' => 'nullable|integer|min:1|max:6', 'factura_por' => 'nullable|in:tienda,erp', 'fecha_corte' => 'nullable|date', 'automatico' => 'boolean', 'recargo_pct' => 'nullable|numeric|min:-50|max:200', 'campos' => 'nullable|array', 'campos.*' => 'in:precio,stock,nombre,descripcion,foto,categoria', 'claves_fiscales' => 'nullable|string|max:200', 'pausar_en_cero' => 'boolean', 'nombre' => 'nullable|string|max:80']);
        if (! empty($d['contact_id'])) Contact::customers()->findOrFail($d['contact_id']);
        $cfg = array_replace($c->cfg(), array_intersect_key($d, Canal::CONFIG));
        if (array_key_exists('campos', $d) && ! $d['campos']) $cfg['campos'] = ['precio', 'stock'];
        $c->fill(['config' => $cfg] + (! empty($d['nombre']) ? ['nombre' => $d['nombre']] : []))->save();
        AuditLog::registrar('editar', $c, "Ajustes de {$c->nombre}");
        return back()->with('success', 'Ajustes guardados.');
    }
}
