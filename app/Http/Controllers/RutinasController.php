<?php

namespace App\Http\Controllers;

use App\Models\BigcitoRutina;
use App\Services\IA\RutinasService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 119.4: las rutinas que cada usuario le dejó encargadas a Bigcito. Cada uno ve y maneja las suyas.
class RutinasController extends Controller
{
    public function index(Request $request, RutinasService $svc)
    {
        $u = $request->user();
        return Inertia::render('Rutinas/Index', [
            'rutinas' => BigcitoRutina::where('user_id', $u->id)->orderByDesc('activa')->orderBy('id')->get()->map(fn($r) => $svc->resumir($r))->values(),
            'frecuencias' => RutinasService::FRECUENCIAS, 'canales' => RutinasService::CANALES, 'dias' => RutinasService::DIAS,
            'puedeWhatsapp' => $svc->puedeWhatsapp($u), 'canalDefecto' => $svc->canalPorDefecto($u), 'conIa' => (bool) config('services.anthropic.api_key'),
            'prueba' => session('prueba'),
            // Fase 119.5: lo que Bigcito recuerda de este usuario y de la empresa.
            'memoria' => app(\App\Services\IA\MemoriaService::class)->para($u), 'puedeMemoriaEmpresa' => app(\App\Services\IA\MemoriaService::class)->puedeEmpresa($u),
            'ejemplos' => [
                ['nombre' => 'Resumen del lunes', 'instruccion' => 'Pasame cómo cerró la semana pasada: cuánto se vendió, cuánto se cobró, cuánto queda por cobrar y los 5 clientes que más deben.', 'frecuencia' => 'semanal', 'dias' => [1], 'hora' => '08:00'],
                ['nombre' => 'Cierre del día', 'instruccion' => 'Decime cuánto se vendió hoy, cuánto entró en caja y bancos y si quedó algo sin cobrar de hoy.', 'frecuencia' => 'habiles', 'hora' => '19:00'],
                ['nombre' => 'Vigilar límites de crédito', 'instruccion' => 'Fijate si algún cliente superó su límite de crédito o tiene facturas vencidas hace más de 30 días y decime quiénes.', 'frecuencia' => 'diaria', 'hora' => '09:00', 'solo_si_hay' => true],
                ['nombre' => 'Qué reponer', 'instruccion' => 'Decime qué artículos están por debajo del mínimo y proponeme las órdenes de compra a su proveedor habitual.', 'frecuencia' => 'semanal', 'dias' => [5], 'hora' => '10:00'],
                ['nombre' => 'Recordatorios de fin de mes', 'instruccion' => 'Buscá los clientes con deuda vencida y proponeme mandarle el recordatorio a cada uno.', 'frecuencia' => 'mensual', 'dia_mes' => 'ultimo', 'hora' => '10:00'],
            ],
        ]);
    }

    public function guardar(Request $request, RutinasService $svc, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'nullable|string|max:120', 'instruccion' => 'required|string|min:5|max:2000', 'frecuencia' => 'required|string', 'hora' => 'required|string|max:10', 'dias' => 'nullable|array', 'dia_mes' => 'nullable', 'canal' => 'nullable|string', 'solo_si_hay' => 'boolean']);
        $u = $request->user();
        if ($id) { $r = BigcitoRutina::where('user_id', $u->id)->findOrFail($id); $svc->actualizar($r, $u, $d); return back()->with('success', 'Rutina actualizada.'); }
        $r = $svc->crear($u, $d);
        return back()->with('success', "Listo: «{$r->nombre}» queda encargada. La próxima es el " . $r->proxima_en->format('d/m \a \l\a\s H:i') . '.');
    }

    public function alternar(Request $request, RutinasService $svc, int $id)
    {
        $r = BigcitoRutina::where('user_id', $request->user()->id)->findOrFail($id);
        $svc->actualizar($r, $request->user(), ['activa' => ! $r->activa]);
        return back()->with('success', $r->activa ? 'Rutina reanudada.' : 'Rutina en pausa.');
    }

    public function borrar(Request $request, int $id)
    {
        BigcitoRutina::where('user_id', $request->user()->id)->findOrFail($id)->delete();
        return back()->with('success', 'Rutina borrada.');
    }

    // Fase 119.5: anotar y borrar lo que Bigcito recuerda.
    public function recordar(Request $request, \App\Services\IA\MemoriaService $svc)
    {
        $d = $request->validate(['texto' => 'required|string|min:4|max:300', 'empresa' => 'boolean']);
        $r = $svc->recordar($request->user(), $d['texto'], (bool) ($d['empresa'] ?? false));
        return isset($r['error']) ? back()->withErrors(['texto' => $r['error']]) : back()->with('success', $r['reemplazo'] ? 'Actualizado: ya tenía algo anotado sobre eso.' : 'Anotado. Bigcito lo va a tener presente.');
    }

    public function olvidar(Request $request, \App\Services\IA\MemoriaService $svc, int $id)
    {
        $r = $svc->olvidar($request->user(), (string) $id);
        return isset($r['error']) ? back()->withErrors(['memoria' => $r['error']]) : back()->with('success', 'Listo, Bigcito ya no lo recuerda.');
    }

    // «Probar ahora»: la hace y muestra el resultado en pantalla, sin mandarlo ni mover el horario.
    public function probar(Request $request, RutinasService $svc, int $id)
    {
        $r = BigcitoRutina::where('user_id', $request->user()->id)->findOrFail($id);
        $res = $svc->ejecutar($r, false);
        return back()->with('prueba', ['id' => $r->id, 'nombre' => $r->nombre, 'texto' => $res['texto'], 'propuestas' => array_map(fn($p) => ['id' => $p['id'], 'titulo' => $p['titulo'], 'detalle' => $p['detalle'] ?? null], $res['propuestas'])]);
    }
}
