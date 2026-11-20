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
            'sucursales' => $b->locations()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'cuit']),
            // Fase 121: facturar a través de BigSys (como el CRM): la empresa delega el servicio en ARCA y no carga certificado.
            'arca' => ['plataforma' => \App\Services\Afip\ArcaPlataforma::configurada(), 'representante' => \App\Services\Afip\ArcaPlataforma::representante(), 'produccion' => (bool) \App\Models\SistemaConfig::get('arca_produccion'),
                // Fase 121.1: el texto que se acepta al activar, y la constancia de la última aceptación.
                'autorizacion' => ['version' => \App\Services\Afip\ArcaAutorizaciones::VERSION, 'texto' => \App\Services\Afip\ArcaAutorizaciones::texto($b->razon_social ?: $b->name, \App\Support\Cuit::formatear(preg_replace('/\D/', '', (string) $b->cuit)) ?? (string) $b->cuit),
                    'ultima' => ($u = \App\Services\Afip\ArcaAutorizaciones::ultima($b)) ? ['usuario' => $u->usuario, 'fecha' => $u->aceptada_en->format('d/m/Y H:i'), 'version' => $u->version, 'revocada' => $u->revocada_en?->format('d/m/Y H:i')] : null],
                'revocar' => session('arca_revocar'), 'puede_aceptar' => $request->user()->esDueno(), 'titular' => \App\Services\Afip\ArcaPlataforma::esTitular($b->cuit),
                'modo' => $afip->modo($b), 'bigsys' => (bool) $b->arca_bigsys, 'verificada' => $b->arca_verificada_en?->format('d/m/Y H:i'), 'cuit' => $b->cuit, 'cuit_ok' => strlen(preg_replace('/\D/', '', (string) $b->cuit)) === 11,
                'sucursales' => $b->locations()->where('is_active', true)->whereNotNull('cuit')->where('cuit', '!=', '')->orderBy('name')->get()->map(fn($l) => ['id' => $l->id, 'nombre' => $l->name, 'cuit' => $l->cuit, 'bigsys' => (bool) $l->arca_bigsys, 'propio' => (bool) ($l->afip_cert_path && $l->afip_key_path)])->values()],
            'afip' => ['configurado' => $afip->configurado($b), 'modo' => $afip->modo($b), 'cuit' => $b->cuit, 'produccion' => $b->afip_produccion, 'cert' => (bool) $b->afip_cert_path, 'key' => (bool) $b->afip_key_path, 'pendientes' => \App\Models\Comprobante::where('estado', 'emitido')->where('afip_estado', 'pendiente')->count()],
            'prueba' => session('afip_prueba'),
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

    // Prueba de conexión con ARCA: estado de los servidores, último comprobante por punto de venta y tipo, y el propio CUIT en el padrón.
    public function probar(Request $request, AfipEmisor $afip)
    {
        $b = $request->user()->business;
        if (! $afip->configurado($b)) return back()->with('error', 'Activá la factura electrónica (a través de BigSys o con tu certificado) antes de probar.');
        $modo = $afip->modo($b);
        $prod = $modo === 'bigsys' ? (bool) \App\Models\SistemaConfig::get('arca_produccion') : (bool) $b->afip_produccion;
        $r = ['modo' => ($prod ? 'Producción' : 'Homologación') . ($modo === 'bigsys' ? ' · a través de BigSys' : ' · certificado propio'), 'ok' => true, 'pasos' => []];
        try {
            $svc = \App\Services\Afip\AfipService::forBusiness($b);
            $st = $svc->getServerStatus();
            $r['pasos'][] = ['nombre' => 'Servidores de ARCA (WSFE)', 'ok' => ($st['AppServer'] ?? '') === 'OK' && ($st['DbServer'] ?? '') === 'OK' && ($st['AuthServer'] ?? '') === 'OK', 'detalle' => 'App ' . ($st['AppServer'] ?? '?') . ' · DB ' . ($st['DbServer'] ?? '?') . ' · Auth ' . ($st['AuthServer'] ?? '?')];
            foreach (PuntoVenta::where('activo', true)->where('modo', 'electronico')->orderBy('numero')->get() as $pv) {
                foreach ([['FA', 1], ['FB', 6], ['FC', 11]] as [$t, $id]) {
                    if (\App\Models\Contact::letraPara($b, null) === 'C' ? $t !== 'FC' : $t === 'FC') continue;
                    try { $n = $svc->getLastVoucher($pv->numero, $id); $r['pasos'][] = ['nombre' => "Punto de venta {$pv->numero} · último {$t}", 'ok' => true, 'detalle' => "Último número en ARCA: {$n}" . ($n === 0 ? ' (todavía no se facturó)' : '')]; }
                    catch (\Throwable $e) { $ex = \App\Services\Afip\AfipErrores::explicar($e->getMessage()); $r['pasos'][] = ['nombre' => "Punto de venta {$pv->numero} · {$t}", 'ok' => false, 'detalle' => "{$ex['que']} {$ex['como']}"]; }
                }
            }
            if ($modo === 'propio') try { $p = $svc->padron((string) $b->cuit); $r['pasos'][] = ['nombre' => 'Padrón (consulta de CUIT)', 'ok' => (bool) $p, 'detalle' => $p ? "Tu CUIT figura como {$p['nombre']} · {$p['condicion_iva']}" : 'No devolvió datos']; }
            catch (\Throwable $e) { $ex = \App\Services\Afip\AfipErrores::explicar($e->getMessage()); $r['pasos'][] = ['nombre' => 'Padrón (consulta de CUIT)', 'ok' => false, 'detalle' => "{$ex['que']} Si no usás la consulta de padrón podés ignorarlo. {$ex['como']}"]; }
        } catch (\Throwable $e) {
            $ex = \App\Services\Afip\AfipErrores::explicar($e->getMessage());
            $r['pasos'][] = ['nombre' => 'Conexión / certificado', 'ok' => false, 'detalle' => "{$ex['que']} {$ex['como']}"];
        }
        $r['ok'] = collect($r['pasos'])->every(fn($p) => $p['ok']);
        AuditLog::registrar('editar', $b, 'Probó la conexión con ARCA: ' . ($r['ok'] ? 'OK' : 'con errores'));
        return back()->with('afip_prueba', $r)->with($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Conexión con ARCA verificada.' : 'La prueba con ARCA encontró problemas. Mirá el detalle.');
    }

    // ---- Fase 121: facturar a través de BigSys ----
    // ¿Los trámites que la empresa hizo en ARCA quedaron bien? Lo contesta ARCA, con una sola consulta (igual que el CRM):
    // responde la lista → delegación hecha (y si está vacía, falta dar de alta el punto de venta); 600 → falta la delegación.
    private function cuitDe(Request $request): array
    {
        $b = $request->user()->business; $loc = null;
        if ($request->filled('location_id')) { $loc = $b->locations()->whereKey((int) $request->input('location_id'))->firstOrFail(); abort_unless($loc->tieneCuitPropio(), 422, 'Esa sucursal no tiene CUIT propio.'); }
        return [$b, $loc, preg_replace('/\D/', '', (string) ($loc?->cuit ?? $b->cuit))];
    }

    public function verificarArca(Request $request)
    {
        [$b, $loc, $cuit] = $this->cuitDe($request);
        if (strlen($cuit) !== 11) return response()->json(['message' => 'Primero cargá el CUIT de la empresa (Configuración → Empresa).'], 422);
        if (! \App\Services\Afip\ArcaPlataforma::configurada()) return response()->json(['message' => 'BigSys todavía no cargó su certificado para facturar. Avisanos y lo activamos.'], 422);
        try {
            $puntos = \App\Services\Afip\ArcaPlataforma::cliente()->puntosVenta($cuit);
        } catch (\App\Services\Afip\ArcaError $e) {
            // Un error que no es 600 no prueba que falte la delegación (puede ser ARCA caído): decir «falta» manda a rehacer un trámite hecho.
            return response()->json(['delegacion' => $e->codigo === '600' ? 'falta' : 'error', 'alta_punto_venta' => 'desconocido', 'puntos' => [], 'mensaje' => $e->getMessage()]);
        }
        $usables = collect($puntos)->filter(fn($p) => ! $p['bloqueado'] && ! $p['baja'])->pluck('numero')->sort()->values()->all();
        $locales = PuntoVenta::where('modo', 'electronico')->where('activo', true)->when($loc, fn($q) => $q->where('business_location_id', $loc->id))->pluck('numero')->all();
        return response()->json(['delegacion' => 'ok', 'alta_punto_venta' => $usables ? 'ok' : 'falta', 'puntos' => $usables,
            'faltan_en_arca' => array_values(array_diff($locales, $usables)), 'faltan_aca' => array_values(array_diff($usables, $locales))]);
    }

    // Activa (o apaga) la facturación por BigSys. Para activarla: ARCA tiene que confirmar la delegación en ese momento y la
    // empresa tiene que aceptar el texto de autorización (queda constancia de quién, cuándo, desde dónde y con qué texto).
    public function bigsys(Request $request)
    {
        $d = $request->validate(['activo' => 'required|boolean', 'location_id' => 'nullable|integer', 'acepto' => 'nullable|boolean', 'version' => 'nullable|string|max:20']);
        [$b, $loc, $cuit] = $this->cuitDe($request);
        $quien = $loc ?? $b; $donde = $loc ? "la sucursal {$loc->name}" : 'la empresa';
        if ($d['activo']) {
            if (strlen($cuit) !== 11) return back()->with('error', 'Primero cargá el CUIT (Configuración → Empresa).');
            if (! $request->user()->esDueno()) return back()->withErrors(['acepto' => 'La autorización a BigSys la acepta el dueño de la cuenta: pedile que entre y la active.']);
            $titular = \App\Services\Afip\ArcaPlataforma::esTitular($cuit); // la empresa del certificado no tiene nada que autorizar
            if (! $titular && empty($d['acepto'])) return back()->withErrors(['acepto' => 'Para activarla tenés que aceptar la autorización a BigSys.']);
            if (! $titular && ($d['version'] ?? null) !== \App\Services\Afip\ArcaAutorizaciones::VERSION) return back()->withErrors(['acepto' => 'El texto de la autorización cambió: recargá la página, leelo y volvé a aceptarlo.']);
            try { \App\Services\Afip\ArcaPlataforma::cliente()->puntosVenta($cuit); }
            catch (\App\Services\Afip\ArcaError $e) {
                $ex = \App\Services\Afip\AfipErrores::explicar($e->getMessage());
                return back()->with('error', "Todavía no se puede activar: {$ex['que']} {$ex['como']}");
            }
            \App\Services\Afip\ArcaAutorizaciones::aceptar($b, $loc, $request->user(), $request->ip(), $request->userAgent());
            return back()->with('success', ($titular ? "Listo: {$donde} ya factura electrónicamente con el certificado de BigSys." : "Listo: {$donde} ya factura electrónicamente a través de BigSys.") . ($quien->afip_cert_path ? ' Ojo: como también tiene certificado propio cargado, se sigue usando ese.' : ''));
        }
        \App\Services\Afip\ArcaAutorizaciones::revocar($b, $loc, $request->user(), 'Apagada desde Configuración → Puntos de venta y ARCA');
        AuditLog::registrar('editar', $b, "Apagó la factura electrónica a través de BigSys para {$donde}");
        return back()->with('success', "Se apagó la facturación a través de BigSys para {$donde}.")->with('arca_revocar', \App\Services\Afip\ArcaAutorizaciones::pasosRevocar());
    }

    // Crea acá los puntos de venta que ARCA tiene dados de alta para web services y todavía no estaban.
    public function traerPuntos(Request $request)
    {
        $d = $request->validate(['numeros' => 'required|array|min:1|max:50', 'numeros.*' => 'integer|min:1|max:99999', 'location_id' => 'nullable|integer']);
        [$b, $loc] = $this->cuitDe($request);
        $n = 0;
        foreach (array_unique($d['numeros']) as $num) {
            if (PuntoVenta::where('numero', $num)->exists()) continue;
            PuntoVenta::create(['business_id' => $b->id, 'business_location_id' => $loc?->id, 'numero' => $num, 'modo' => 'electronico', 'activo' => true]); $n++;
        }
        AuditLog::registrar('crear', $b, "Trajo {$n} punto(s) de venta de ARCA");
        return back()->with('success', $n ? "Se agregaron {$n} punto(s) de venta de ARCA." : 'Esos puntos de venta ya estaban.');
    }

    public function certificados(Request $request)
    {
        $request->validate(['cert' => 'required|file|max:64', 'key' => 'required|file|max:64']);
        $b = $request->user()->business;
        $cert = file_get_contents($request->file('cert')->getRealPath()); $key = file_get_contents($request->file('key')->getRealPath());
        if (! str_contains($cert, '-----BEGIN') || ! str_contains($key, '-----BEGIN')) return back()->withErrors(['cert' => 'El certificado y la clave tienen que ser archivos PEM (empiezan con -----BEGIN).']);
        // Se guardan cifrados con la clave de la aplicación; el servicio de ARCA los descifra a un archivo temporal al usarlos.
        $b->update([
            'afip_cert_path' => \App\Services\Afip\CertificadoCifrado::guardar($b, 'cert.crt', $cert),
            'afip_key_path'  => \App\Services\Afip\CertificadoCifrado::guardar($b, 'private.key', $key),
        ]);
        AuditLog::registrar('editar', $b, 'Cargó certificados ARCA');
        return back()->with('success', 'Certificados ARCA cargados. Ya podés emitir comprobantes electrónicos.');
    }
}
