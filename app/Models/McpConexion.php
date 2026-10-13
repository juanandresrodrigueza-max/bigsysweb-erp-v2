<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Una IA conectada al ERP por MCP en nombre de un usuario (Claude, ChatGPT, un token personal…).
class McpConexion extends Model
{
    use BelongsToBusiness;

    protected $table = 'mcp_conexiones';
    protected $fillable = ['business_id', 'user_id', 'nombre', 'client_id', 'token_id', 'refresh_hash', 'modo', 'ultimo_uso', 'usos', 'revocada_en'];
    protected $casts = ['ultimo_uso' => 'datetime', 'revocada_en' => 'datetime'];

    public const MODOS = ['lectura' => 'Solo consultas', 'acciones' => 'Consultas y acciones con confirmación', 'delicadas' => 'También acciones delicadas (emitir, anular, pagar)'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function permite(string $nivel): bool
    {
        $orden = ['lectura' => 0, 'acciones' => 1, 'delicadas' => 2];
        return ($orden[$this->modo] ?? 0) >= ($orden[$nivel] ?? 0);
    }
}
