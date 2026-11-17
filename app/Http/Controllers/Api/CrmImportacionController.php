<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integraciones\CrmImportacionService;
use Illuminate\Http\Request;

// Fase 116 · Carga inicial CRM → ERP, en lotes de hasta 500 e idempotente (botón «Pasar todo al ERP» del CRM). Token de API de la empresa.
class CrmImportacionController extends Controller
{
    private function items(Request $r): array { return $r->validate(['items' => 'required|array|min:1|max:' . CrmImportacionService::LOTE])['items']; }

    public function contactos(Request $r, CrmImportacionService $s) { return response()->json(['data' => $s->contactos($r->user()->business, $this->items($r))]); }
    public function presupuestos(Request $r, CrmImportacionService $s) { return response()->json(['data' => $s->presupuestos($r->user()->business, $this->items($r))]); }
    public function comprobantesHistoricos(Request $r, CrmImportacionService $s) { return response()->json(['data' => $s->comprobantesHistoricos($r->user()->business, $this->items($r))]); }
    public function saldosIniciales(Request $r, CrmImportacionService $s) { return response()->json(['data' => $s->saldosIniciales($r->user()->business, $this->items($r))]); }
}
