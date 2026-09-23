<?php

namespace App\Http\Controllers;

use App\Support\Catalogo;
use Illuminate\Http\Request;

// Búsqueda incremental para los selectores de los formularios cuando el catálogo es grande.
class BuscarController extends Controller
{
    public function __invoke(Request $request, string $entidad, string $forma)
    {
        abort_unless(in_array($entidad, ['articulos', 'contactos'], true) && in_array($forma, ['venta', 'compra', 'orden', 'produccion', 'cliente', 'proveedor'], true), 404);
        $ids = array_filter(array_map('intval', explode(',', (string) $request->input('ids', ''))));
        return response()->json(Catalogo::buscar($entidad, $forma, (string) $request->input('q', ''), $ids));
    }
}
