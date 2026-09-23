<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integraciones\ExportacionService;
use Illuminate\Http\Request;

// Mandar las tablas del ERP a otra plataforma: GET /api/exportar (catálogo) y GET /api/exportar/{tabla} (filas paginadas, JSON o CSV).
class ExportacionController extends Controller
{
    public function index()
    {
        return response()->json([
            'como_usar' => 'GET /api/exportar/{tabla}?page=1&per_page=500&desde=YYYY-MM-DD&hasta=YYYY-MM-DD&actualizado_desde=YYYY-MM-DDTHH:MM:SS&id_desde=0&formato=json|csv. Recorré las páginas hasta que "siguiente" sea null. Para sincronizar solo lo nuevo, guardá la fecha/hora de tu última corrida y mandala en actualizado_desde.',
            'tablas' => ExportacionService::catalogo(),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function tabla(Request $request, string $tabla)
    {
        abort_unless($request->user()->puede('configuracion') || $request->user()->puede('estadisticas'), 403, 'El usuario del token no tiene permiso para exportar datos.');
        $f = $request->validate(['desde' => 'nullable|date', 'hasta' => 'nullable|date', 'actualizado_desde' => 'nullable|date', 'id_desde' => 'nullable|integer|min:0', 'sucursal_id' => 'nullable|integer', 'per_page' => 'nullable|integer|min:1|max:' . ExportacionService::MAX_POR_PAGINA, 'formato' => 'nullable|in:json,csv', 'page' => 'nullable|integer|min:1']);
        $q = ExportacionService::consulta($tabla, $request->user()->business_id, $f);
        $columnas = ExportacionService::columnas(ExportacionService::TABLAS[$tabla][0]);
        $porPagina = (int) ($f['per_page'] ?? 200);
        \App\Models\AuditLog::registrar('exportar', null, "Exportó la tabla {$tabla} por API" . ($f ? ' (' . http_build_query($f, '', ', ') . ')' : ''));

        if (($f['formato'] ?? 'json') === 'csv') {
            $nombre = $tabla . '-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($q, $columnas) {
                echo "\xEF\xBB\xBF"; $h = fopen('php://output', 'w'); fputcsv($h, $columnas, ';');
                foreach ($q->cursor() as $r) fputcsv($h, array_map(fn($v) => is_bool($v) ? (int) $v : $v, (array) $r), ';');
                fclose($h);
            }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $p = $q->paginate($porPagina)->appends($request->query());
        return response()->json([
            'tabla' => $tabla, 'pagina' => $p->currentPage(), 'por_pagina' => $p->perPage(), 'total' => $p->total(), 'ultima_pagina' => $p->lastPage(),
            'siguiente' => $p->nextPageUrl(), 'columnas' => $columnas, 'datos' => $p->items(),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
