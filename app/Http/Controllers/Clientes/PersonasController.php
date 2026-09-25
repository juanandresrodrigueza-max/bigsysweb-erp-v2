<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ContactoPersona;
use Illuminate\Http\Request;

// Personas de contacto de clientes y proveedores.
class PersonasController extends Controller
{
    public function index(int $contacto)
    {
        return response()->json(Contact::findOrFail($contacto)->personas->map->datos());
    }

    public function guardar(Request $request, int $contacto, ?int $id = null)
    {
        $c = Contact::findOrFail($contacto);
        $d = $request->validate(['nombre' => 'required|string|max:100', 'cargo' => 'nullable|string|max:80', 'telefono' => 'nullable|string|max:40', 'email' => 'nullable|email|max:150', 'notas' => 'nullable|string|max:500',
            'recibe_comprobantes' => 'boolean', 'recibe_cobranzas' => 'boolean', 'recibe_pagos' => 'boolean']);
        $p = $id ? ContactoPersona::where('contact_id', $c->id)->findOrFail($id) : new ContactoPersona(['business_id' => $c->business_id, 'contact_id' => $c->id]);
        $p->fill($d)->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $c, "Contacto {$p->nombre} de {$c->name}");
        return response()->json($p->datos());
    }

    public function borrar(int $contacto, int $id)
    {
        $p = ContactoPersona::where('contact_id', $contacto)->findOrFail($id);
        AuditLog::registrar('eliminar', $p->contact, "Quitó el contacto {$p->nombre}");
        $p->delete();
        return response()->json(['ok' => true]);
    }
}
