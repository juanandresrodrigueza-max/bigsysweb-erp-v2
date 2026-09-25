<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Documento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Documentos adjuntos (Fase 26.6): contratos, constancias de CUIT, fichas técnicas, fotos, planillas.
class DocumentosController extends Controller
{
    private function duenio(string $tipo, int $id)
    {
        abort_unless(isset(Documento::TIPOS[$tipo]), 404);
        return Documento::TIPOS[$tipo]::findOrFail($id); // el scope de empresa hace que otra empresa reciba 404
    }

    public function index(string $tipo, int $id)
    {
        $m = $this->duenio($tipo, $id);
        return response()->json(Documento::with('user:id,name')->where('adjuntable_type', get_class($m))->where('adjuntable_id', $m->id)->orderByDesc('fecha')->orderByDesc('id')->get()->map->datos());
    }

    public function subir(Request $request, string $tipo, int $id)
    {
        $m = $this->duenio($tipo, $id);
        $d = $request->validate(['archivo' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp,gif,xlsx,xls,csv,docx,doc,txt,zip', 'nombre' => 'nullable|string|max:150', 'fecha' => 'nullable|date', 'notas' => 'nullable|string|max:300']);
        $f = $request->file('archivo');
        $b = $request->user()->business_id;
        $path = $f->store("documentos/{$b}", 'local');
        $doc = Documento::create(['business_id' => $b, 'user_id' => $request->user()->id, 'adjuntable_type' => get_class($m), 'adjuntable_id' => $m->id,
            'nombre' => ($d['nombre'] ?? null) ?: $f->getClientOriginalName(), 'archivo' => $path, 'mime' => $f->getMimeType(), 'tamano' => $f->getSize(), 'fecha' => $d['fecha'] ?? today()->toDateString(), 'notas' => $d['notas'] ?? null]);
        AuditLog::registrar('crear', $m, "Adjuntó el documento {$doc->nombre}");
        return response()->json($doc->load('user:id,name')->datos());
    }

    public function descargar(Request $request, int $doc)
    {
        $d = Documento::findOrFail($doc);
        abort_unless(Storage::disk('local')->exists($d->archivo), 404);
        $ext = pathinfo($d->archivo, PATHINFO_EXTENSION);
        $nombre = str_ends_with(strtolower($d->nombre), '.' . strtolower($ext)) ? $d->nombre : $d->nombre . '.' . $ext;
        $inline = $request->boolean('ver') && (str_starts_with((string) $d->mime, 'image/') || $d->mime === 'application/pdf');
        return $inline ? Storage::disk('local')->response($d->archivo, $nombre, ['Content-Type' => $d->mime]) : Storage::disk('local')->download($d->archivo, $nombre);
    }

    public function borrar(int $doc)
    {
        $d = Documento::findOrFail($doc);
        Storage::disk('local')->delete($d->archivo);
        AuditLog::registrar('eliminar', $d->adjuntable, "Quitó el documento {$d->nombre}");
        $d->delete();
        return response()->json(['ok' => true]);
    }
}
