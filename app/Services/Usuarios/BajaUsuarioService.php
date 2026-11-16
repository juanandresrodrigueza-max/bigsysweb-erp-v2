<?php

namespace App\Services\Usuarios;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Fase 112: eliminar usuarios. Si la persona nunca hizo nada (sin ventas, cobros, asientos ni auditoría atada), se borra de verdad.
// Si tiene historial, no se puede borrar sin romperlo: se anonimiza (nombre «Usuario eliminado», mail inservible, sin acceso, sin
// empresa ni rol) y desaparece de las listas, pero cada comprobante y movimiento sigue diciendo quién lo hizo. Nunca al dueño de una
// empresa, nunca a uno mismo, nunca al último superadmin.
class BajaUsuarioService
{
    public const DOMINIO_BORRADO = 'borrado.local';

    public static function esBorrado(User $u): bool { return str_ends_with((string) $u->email, '@' . self::DOMINIO_BORRADO); }

    public function eliminar(User $u, User $por): array
    {
        if ($u->id === $por->id) throw ValidationException::withMessages(['usuario' => 'No podés eliminarte a vos mismo.']);
        if ($empresa = Business::where('owner_id', $u->id)->first()) throw ValidationException::withMessages(['usuario' => "{$u->name} es el dueño de {$empresa->name}. Primero pasá la titularidad a otro usuario (Configuración → Empresa) o dá de baja la empresa."]);
        if ($u->is_superadmin && User::where('is_superadmin', true)->where('status', 'active')->where('id', '!=', $u->id)->doesntExist()) throw ValidationException::withMessages(['usuario' => 'Es el único superadmin activo: creá otro antes de eliminarlo.']);

        $email = $u->email; $nombre = $u->name; $businessId = $u->business_id;
        AuditLog::registrar('eliminar', $u, "Usuario eliminado: {$nombre} <{$email}>" . ($por->is_superadmin ? ' (superadmin)' : ''));
        if ($businessId) \App\Services\Integraciones\CrmSyncService::encolar($businessId, 'usuarios', [$u->id]);

        $u->locations()->detach();
        try {
            DB::transaction(fn() => $u->delete());
            return ['modo' => 'borrado', 'mensaje' => "Se eliminó a {$nombre} ({$email})."];
        } catch (QueryException $e) {
            // Tiene movimientos atados: se deja irreconocible y sin acceso, y los movimientos conservan quién los hizo.
            $u->forceFill([
                'name' => 'Usuario eliminado', 'email' => "eliminado-{$u->id}@" . self::DOMINIO_BORRADO, 'password' => Str::random(40), 'status' => 'inactive',
                'is_superadmin' => false, 'mobile' => null, 'avatar' => null, 'remember_token' => null, 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_enabled_at' => null,
                'business_id' => null, 'role_id' => null, 'current_location_id' => null,
            ])->save();
            return ['modo' => 'anonimizado', 'mensaje' => "{$nombre} tenía movimientos cargados: se le quitó el acceso y quedó como «Usuario eliminado» en el historial. No se borró ningún comprobante."];
        }
    }
}
