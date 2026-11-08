<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ventas\ComisionesService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// Fase 74: la base de comisiones con lo facturado y cobrado de verdad, para que el CRM liquide sobre eso.
class ComisionesController extends Controller
{
    public function base(Request $r, ComisionesService $svc)
    {
        $r->validate(['desde' => 'nullable|date', 'hasta' => 'nullable|date|after_or_equal:desde', 'detalle' => 'nullable|boolean']);
        $d = $r->filled('desde') ? Carbon::parse($r->desde) : today()->startOfMonth();
        $h = $r->filled('hasta') ? Carbon::parse($r->hasta) : today();
        abort_if($d->diffInDays($h) > 370, 422, 'El período no puede pasar de un año.');
        return response()->json(['data' => $svc->base($r->user()->business, $d, $h, $r->boolean('detalle', true))]);
    }
}
