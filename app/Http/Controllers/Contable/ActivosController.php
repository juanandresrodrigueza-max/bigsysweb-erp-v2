<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\ActivoFijo;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Services\Contabilidad\ActivosService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Bienes de uso: alta, cuadro de amortizaciones, amortizar el mes y baja o venta.
class ActivosController extends Controller
{
    public function index(Request $request, ActivosService $svc)
    {
        $b = $request->user()->business;
        $q = ActivoFijo::with('location:id,name')->when($request->estado, fn($q, $e) => $q->where('estado', $e), fn($q) => $q->where('estado', 'activo'))->orderBy('categoria')->orderBy('nombre');
        $filas = $q->get()->map(fn($a) => ['id' => $a->id, 'nombre' => $a->nombre, 'categoria' => $a->categoria, 'categoria_label' => ActivoFijo::CATEGORIAS[$a->categoria][0] ?? $a->categoria, 'identificacion' => $a->identificacion, 'fecha_alta' => $a->fecha_alta->format('d/m/Y'), 'valor_origen' => (float) $a->valor_origen, 'valor_residual' => (float) $a->valor_residual, 'vida_util_meses' => $a->vida_util_meses, 'amortizado' => (float) $a->amortizado, 'residual_contable' => $a->valorResidualContable(), 'cuota' => $a->cuotaMensual(), 'meses' => $a->mesesAmortizados(), 'pct' => (float) $a->valor_origen > 0 ? round((float) $a->amortizado / (float) $a->valor_origen * 100) : 0, 'estado' => $a->estado, 'fecha_baja' => $a->fecha_baja?->format('d/m/Y'), 'valor_baja' => (float) $a->valor_baja, 'sucursal' => $a->location?->name, 'notas' => $a->notas]);
        if ($request->export) {
            $csv = "Bien;Categoría;Identificación;Alta;Valor origen;Valor residual;Vida útil (meses);Amortizado;Valor contable;Cuota mensual;Estado\n";
            foreach ($filas as $f) $csv .= implode(';', [$f['nombre'], $f['categoria_label'], $f['identificacion'], $f['fecha_alta'], number_format($f['valor_origen'], 2, ',', ''), number_format($f['valor_residual'], 2, ',', ''), $f['vida_util_meses'], number_format($f['amortizado'], 2, ',', ''), number_format($f['residual_contable'], 2, ',', ''), number_format($f['cuota'], 2, ',', ''), ActivoFijo::ESTADOS[$f['estado']]]) . "\n";
            AuditLog::registrar('exportar', null, 'Exportó el cuadro de bienes de uso');
            return response("\xEF\xBB\xBF" . $csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=bienes_de_uso.csv']);
        }
        return Inertia::render('Contable/Activos', [
            'activos' => $filas, 'categorias' => collect(ActivoFijo::CATEGORIAS)->map(fn($c) => ['label' => $c[0], 'meses' => $c[1]]), 'estados' => ActivoFijo::ESTADOS, 'filtros' => $request->only('estado'),
            'resumen' => $svc->resumen($b), 'periodo' => today()->format('Y-m'),
            'proveedores' => Contact::suppliers()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function guardar(Request $request, ActivosService $svc, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:150', 'categoria' => 'required|in:' . implode(',', array_keys(ActivoFijo::CATEGORIAS)), 'identificacion' => 'nullable|string|max:80', 'fecha_alta' => 'required|date', 'valor_origen' => 'required|numeric|min:0', 'valor_residual' => 'nullable|numeric|min:0', 'vida_util_meses' => 'required|integer|min:1|max:1200', 'notas' => 'nullable|string|max:500', 'origen' => 'nullable|in:ninguno,compra,aporte,reclasificar', 'contact_id' => 'nullable|integer', 'comprobante_id' => 'nullable|integer']);
        if ($id) { $a = ActivoFijo::findOrFail($id); $a->update(collect($d)->only(['nombre', 'categoria', 'identificacion', 'notas', 'valor_residual', 'vida_util_meses'])->all()); return back()->with('success', 'Bien actualizado.'); }
        $a = $svc->alta(collect($d)->only(['nombre', 'categoria', 'identificacion', 'fecha_alta', 'valor_origen', 'valor_residual', 'vida_util_meses', 'notas', 'comprobante_id'])->all(), $d['origen'] ?? 'ninguno', $d['contact_id'] ?? null);
        return back()->with('success', "Bien de uso {$a->nombre} dado de alta.");
    }

    public function amortizar(Request $request, ActivosService $svc)
    {
        $d = $request->validate(['periodo' => 'required|date_format:Y-m']);
        $r = $svc->amortizar($request->user()->business, $d['periodo']);
        return back()->with('success', $r['n'] ? "Amortización de {$d['periodo']}: {$r['n']} bienes por $ " . number_format($r['total'], 2, ',', '.') . '. Asiento generado.' : 'No había nada para amortizar en ese período (ya estaba hecho o los bienes están totalmente amortizados).');
    }

    public function baja(Request $request, int $id, ActivosService $svc)
    {
        $d = $request->validate(['fecha' => 'required|date', 'valor_venta' => 'nullable|numeric|min:0', 'motivo' => 'nullable|string|max:200']);
        $a = $svc->baja(ActivoFijo::findOrFail($id), $d['fecha'], (float) ($d['valor_venta'] ?? 0), $d['motivo'] ?? null);
        return back()->with('success', ($a->estado === 'vendido' ? 'Venta' : 'Baja') . " de {$a->nombre} registrada con su asiento.");
    }
}
