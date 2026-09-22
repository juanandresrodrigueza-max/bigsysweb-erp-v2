<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class SucursalController extends Controller
{
    public function cambiar(Request $request, int $id)
    {
        $user = $request->user();
        $sucursal = $user->sucursalesAccesibles()->firstWhere('id', $id);
        abort_unless($sucursal, 403, 'No tenés acceso a esa sucursal.');

        $user->forceFill(['current_location_id' => $sucursal->id])->save();
        AuditLog::registrar('cambio_sucursal', $sucursal, "Cambió a la sucursal {$sucursal->name}");

        return back()->with('success', "Ahora estás trabajando en {$sucursal->name}.");
    }
}
