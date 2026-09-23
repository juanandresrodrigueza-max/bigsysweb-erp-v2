<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiDocs;
use Illuminate\Support\Str;

// /api/docs: página de referencia generada desde las rutas; /api/openapi.json: especificación para importar en Postman, Insomnia o Swagger.
class DocsController extends Controller
{
    public function index()
    {
        $guia = file_exists(base_path('docs/api.md')) ? Str::markdown(file_get_contents(base_path('docs/api.md')), ['html_input' => 'strip', 'allow_unsafe_links' => false]) : '';
        return view('api.docs', ['grupos' => ApiDocs::grupos(), 'guia' => $guia, 'base' => url('/api'), 'total' => count(ApiDocs::endpoints())]);
    }

    public function openapi()
    {
        return response()->json(ApiDocs::openapi(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
