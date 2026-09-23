<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Services\Migracion\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

// Copias de seguridad, restauración y exportación total de los datos de la empresa.
class DatosController extends Controller
{
    public function index(Request $request)
    {
        $b = $request->user()->business;
        return Inertia::render('Configuracion/Datos', [
            'backups' => Backup::with('user:id,name')->latest()->limit(30)->get()->map(fn($k) => ['id' => $k->id, 'fecha' => $k->created_at->format('d/m/Y H:i'), 'origen' => $k->origen, 'mb' => round($k->bytes / 1048576, 2), 'tablas' => count($k->resumen ?? []), 'filas' => array_sum($k->resumen ?? []), 'usuario' => $k->user?->name, 'resumen' => $k->resumen]),
            'backupAuto' => (bool) $b->backup_auto,
            'tablas' => BackupService::TABLAS,
        ]);
    }

    public function crear(Request $request, BackupService $svc)
    {
        $bk = $svc->crear($request->user()->business, 'manual', $request->user()->id);
        AuditLog::registrar('crear', $bk, 'Generó una copia de seguridad');
        return back()->with('success', 'Copia de seguridad lista: ' . round($bk->bytes / 1024) . ' KB, ' . count($bk->resumen) . ' tablas.');
    }

    public function descargar(Request $request, int $id)
    {
        $bk = Backup::findOrFail($id);
        AuditLog::registrar('exportar', $bk, 'Descargó una copia de seguridad');
        return Storage::disk('local')->download($bk->archivo, basename($bk->archivo));
    }

    public function eliminar(int $id)
    {
        $bk = Backup::findOrFail($id);
        Storage::disk('local')->delete($bk->archivo); $bk->delete();
        return back()->with('success', 'Copia eliminada.');
    }

    public function restaurar(Request $request, BackupService $svc)
    {
        $d = $request->validate(['backup_id' => 'nullable|integer', 'archivo' => 'nullable|file|max:512000|mimes:zip', 'confirmacion' => 'required|in:RESTAURAR']);
        $b = $request->user()->business;
        $path = $request->hasFile('archivo') ? $request->file('archivo')->getRealPath() : Storage::disk('local')->path(Backup::findOrFail($d['backup_id'])->archivo);
        $r = $svc->restaurar($b, $path, $request->user()->id);
        return back()->with('success', 'Datos restaurados: ' . array_sum($r) . ' filas en ' . count($r) . ' tablas. Antes se guardó una copia del estado anterior por si hace falta volver.');
    }

    public function backupAuto(Request $request)
    {
        $b = $request->user()->business;
        $b->update(['backup_auto' => ! $b->backup_auto]);
        return back()->with('success', $b->backup_auto ? 'Copia automática diaria activada (todas las noches a las 3).' : 'Copia automática desactivada.');
    }
}
