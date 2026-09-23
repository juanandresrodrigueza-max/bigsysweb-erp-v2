<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Business;
use Illuminate\Http\Request;

// Usuarios con varias empresas (típicamente el contador): cambian de empresa sin salir del sistema.
class EmpresaSwitchController extends Controller
{
    public function cambiar(Request $request, int $id)
    {
        $user = $request->user();
        $destino = $user->empresasAccesibles()->firstWhere('id', $id);
        abort_unless($destino, 403, 'No tenés acceso a esa empresa.');
        $user->cambiarEmpresa($destino);
        AuditLog::registrar('cambio_empresa', $destino, "Entró a la empresa {$destino->name}");
        return redirect($request->input('a', '/dashboard'))->with('success', "Ahora estás en {$destino->name}.");
    }
}
