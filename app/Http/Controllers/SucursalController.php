<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class SucursalController extends Controller
{
    // Casa central: ver el tablero y las estadísticas de todas las sucursales juntas.
    public function consolidado(Request $request)
    {
        $user = $request->user();
        abort_unless($user->esDueno() || $user->puede('estadisticas'), 403);
        $user->forceFill(['ver_consolidado' => ! $user->ver_consolidado])->save();
        return back()->with('success', $user->ver_consolidado ? 'Estás viendo el consolidado de todas las sucursales.' : 'Volviste a ver solo tu sucursal.');
    }

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
