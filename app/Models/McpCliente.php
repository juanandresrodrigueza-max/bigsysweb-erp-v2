<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Una aplicación MCP registrada por OAuth (registro dinámico): Claude, ChatGPT, Cursor… No pertenece a una empresa.
class McpCliente extends Model
{
    protected $table = 'mcp_clientes';
    protected $fillable = ['client_id', 'nombre', 'redirect_uris'];
    protected $casts = ['redirect_uris' => 'array'];
}
