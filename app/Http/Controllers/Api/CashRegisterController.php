<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashRegisterController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(CashRegister::with('activeSession')->get());
    }

    public function open(Request $request, CashRegister $cashRegister): JsonResponse
    {
        $data = $request->validate([
            'opening_amount' => 'required|numeric|min:0',
        ]);

        if ($cashRegister->activeSession) {
            return response()->json(['message' => 'La caja ya está abierta.'], 422);
        }

        $session = CashRegisterSession::create([
            'business_id'      => $request->user()->business_id,
            'cash_register_id' => $cashRegister->id,
            'user_id'          => $request->user()->id,
            'status'           => 'open',
            'opening_amount'   => $data['opening_amount'],
            'opened_at'        => now(),
        ]);

        return response()->json($session->load('cashRegister'), 201);
    }

    public function close(Request $request, CashRegister $cashRegister): JsonResponse
    {
        $data = $request->validate([
            'closing_amount' => 'required|numeric|min:0',
            'closing_notes'  => 'nullable|string',
        ]);

        $session = $cashRegister->activeSession;

        if (! $session) {
            return response()->json(['message' => 'La caja no está abierta.'], 422);
        }

        $expected = $session->opening_amount + $session->sales()->sum('total');

        $session->update([
            'status'          => 'closed',
            'closing_amount'  => $data['closing_amount'],
            'expected_amount' => $expected,
            'difference'      => $data['closing_amount'] - $expected,
            'closing_notes'   => $data['closing_notes'] ?? null,
            'closed_at'       => now(),
        ]);

        return response()->json($session->load(['cashRegister', 'user']));
    }
}
