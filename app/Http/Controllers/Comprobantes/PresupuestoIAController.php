<?php

namespace App\Http\Controllers\Comprobantes;

use App\Http\Controllers\Controller;
use App\Models\ComprobanteAdjunto;
use App\Services\Comprobantes\PresupuestoIAService;
use Illuminate\Http\Request;

class PresupuestoIAController extends Controller
{
    public function interpretar(Request $request, PresupuestoIAService $ia)
    {
        $data = $request->validate([
            'texto'  => 'nullable|string|max:5000',
            'imagen' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
            'lista'  => 'nullable|integer|min:1|max:5',
        ]);
        abort_if(empty($data['texto']) && ! $request->hasFile('imagen'), 422, 'Pegá el mensaje o subí una foto del pedido.');

        $b64 = null; $mime = null; $path = null;
        if ($request->hasFile('imagen')) {
            $f = $request->file('imagen');
            $mime = $f->getMimeType();
            $b64 = base64_encode(file_get_contents($f->getRealPath()));
            $path = $f->store("adjuntos/{$request->user()->business_id}", 'local');
        }

        $res = $ia->interpretar($data['texto'] ?? null, $b64, $mime, (int) ($data['lista'] ?? 1));

        ComprobanteAdjunto::create([
            'business_id' => $request->user()->business_id, 'user_id' => $request->user()->id,
            'tipo' => $path ? 'imagen' : 'texto', 'archivo' => $path, 'transcripcion' => $data['texto'] ?? null,
            'items_detectados' => $res['items'], 'confianza' => count($res['items']) ? round(collect($res['items'])->avg('confianza'), 2) : null,
        ]);

        return response()->json($res);
    }
}
