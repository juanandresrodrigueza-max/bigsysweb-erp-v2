<?php

namespace App\Services\Afip;

use App\Models\ArcaAutorizacion;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\User;

// Fase 121.1: la autorización expresa para que BigSys emita comprobantes en nombre de una empresa.
//
// La delegación «de verdad» es la que la empresa hace en ARCA con su clave fiscal. Esto es la otra mitad: que la empresa
// acepte, dentro del sistema, un texto claro sobre qué hace BigSys y qué no, y que quede constancia de quién lo aceptó,
// cuándo, desde dónde y con qué texto exacto. Si el texto cambia, se sube la versión y las activaciones nuevas aceptan
// la versión nueva (las anteriores conservan la que aceptaron).
class ArcaAutorizaciones
{
    public const VERSION = '2026-11-21'; // agrega la declaración de facultades de quien acepta

    // Lo que se le muestra a la empresa al lado del tilde, y lo que queda guardado.
    public static function texto(string $razon, string $cuit, ?array $rep = null): string
    {
        $rep ??= ArcaPlataforma::representante() ?? ['razon' => 'BigSys S.R.L.', 'cuit' => '(CUIT de BigSys)'];
        // Fase 122.1: la propia empresa del certificado no se autoriza a sí misma; queda constancia de que emite con su certificado.
        if (preg_replace('/\D/', '', $cuit) === preg_replace('/\D/', '', (string) $rep['cuit']))
            return "{$razon} (CUIT {$cuit}) emite sus propios comprobantes electrónicos con su certificado de ARCA, el mismo con el que BigSys factura para sus clientes. No hay delegación de terceros.";
        return "{$razon} (CUIT {$cuit}) autoriza a {$rep['razon']} (CUIT {$rep['cuit']}) a emitir y consultar comprobantes electrónicos ante ARCA en su nombre, "
            . "con el servicio «Facturación Electrónica» que le delegó en el Administrador de Relaciones de Clave Fiscal. "
            . "BigSys emite sólo los comprobantes que generan los usuarios de la empresa en el sistema, con los datos que ellos cargan; "
            . "la empresa sigue siendo el emisor y la responsable de esos datos y de sus obligaciones fiscales. "
            . "Esta autorización se puede dejar sin efecto en cualquier momento, apagando la facturación por BigSys en el sistema "
            . "y revocando la relación en ARCA. Rigen los Términos de uso de BigSys Web. "
            . "Quien la acepta declara tener facultades suficientes para obligar a la empresa.";
    }

    // Activa: registra la constancia y prende la facturación por BigSys de la empresa o de la sucursal.
    public static function aceptar(Business $b, ?BusinessLocation $loc, User $u, ?string $ip, ?string $navegador): ArcaAutorizacion
    {
        $quien = $loc ?? $b;
        $cuit = \App\Support\Cuit::formatear(preg_replace('/\D/', '', (string) $quien->cuit)) ?? (string) $quien->cuit;
        $razon = $loc ? ($loc->razon_social ?: $loc->name) : ($b->razon_social ?: $b->name);
        $rep = ArcaPlataforma::representante();
        // Si había una anterior vigente (se reactiva), queda cerrada: la nueva la reemplaza.
        self::vigentes($b, $loc)->each(fn($a) => $a->update(['revocada_en' => now(), 'revocada_por' => self::persona($u), 'motivo_revocacion' => 'Reemplazada por una nueva aceptación']));
        $a = ArcaAutorizacion::create([
            'business_id' => $b->id, 'sucursal_id' => $loc?->id, 'user_id' => $u->id, 'usuario' => self::persona($u),
            'cuit' => $cuit, 'razon_social' => mb_substr((string) $razon, 0, 150), 'representante' => $rep ? "{$rep['razon']} (CUIT {$rep['cuit']})" : null,
            'version' => self::VERSION, 'texto' => self::texto((string) $razon, $cuit, $rep), 'ip' => $ip, 'navegador' => $navegador ? mb_substr($navegador, 0, 300) : null, 'aceptada_en' => now(),
        ]);
        $quien->forceFill(['arca_bigsys' => true, 'arca_verificada_en' => now()])->save();
        AuditLog::registrar('editar', $b, "Autorizó a BigSys a emitir comprobantes en su nombre (CUIT {$cuit}, texto versión " . self::VERSION . ", IP {$ip})");
        return $a;
    }

    // Apaga: la facturación por BigSys se corta y la constancia queda marcada como revocada (no se borra).
    public static function revocar(Business $b, ?BusinessLocation $loc, ?User $u, string $motivo): int
    {
        $quien = $loc ?? $b;
        $n = 0;
        foreach (self::vigentes($b, $loc) as $a) { $a->update(['revocada_en' => now(), 'revocada_por' => $u ? self::persona($u) : 'Sistema', 'motivo_revocacion' => mb_substr($motivo, 0, 200)]); $n++; }
        if ($quien->arca_bigsys) $quien->forceFill(['arca_bigsys' => false])->save();
        return $n;
    }

    // Al dar de baja la empresa: se apaga en la empresa y en todas sus sucursales, y se le explica al dueño cómo revocar en ARCA.
    public static function alDarDeBaja(Business $b, string $motivo): array
    {
        $tenia = (bool) $b->arca_bigsys; $cuits = [];
        if ($tenia) $cuits[] = $b->cuit;
        self::revocar($b, null, null, 'Baja de la empresa: ' . $motivo);
        foreach (BusinessLocation::withoutGlobalScopes()->where('business_id', $b->id)->where('arca_bigsys', true)->get() as $l) {
            $cuits[] = $l->cuit; self::revocar($b, $l, null, 'Baja de la empresa: ' . $motivo);
        }
        if (! $cuits) return [];
        $owner = User::withoutGlobalScopes()->find($b->owner_id);
        if ($owner?->email) {
            try { \Illuminate\Support\Facades\Mail::raw(self::textoRevocar($b->name, $cuits), fn($m) => $m->to($owner->email)->subject("BigSys: revocá la autorización de facturación en ARCA · {$b->name}")); }
            catch (\Throwable $e) { report($e); }
        }
        AuditLog::registrar('editar', $b, 'Baja: se apagó la facturación por BigSys y se avisó cómo revocar la relación en ARCA (' . implode(', ', $cuits) . ')');
        return $cuits;
    }

    // Cómo se revoca la relación en ARCA. Lo ve quien apaga la facturación y le llega al dueño cuando da de baja la empresa.
    public static function pasosRevocar(): array
    {
        $rep = ArcaPlataforma::representante();
        return [
            'Entrá a ARCA con la clave fiscal del CUIT que factura.',
            'Abrí «Administrador de Relaciones de Clave Fiscal».',
            'Buscá la relación del servicio «Facturación Electrónica» con ' . ($rep ? "{$rep['razon']} (CUIT {$rep['cuit']})" : 'BigSys') . ' como representante.',
            'Tocá «Revocar» y confirmá.',
        ];
    }

    public static function textoRevocar(string $empresa, array $cuits): string
    {
        $p = collect(self::pasosRevocar())->map(fn($x, $i) => ($i + 1) . ". {$x}")->implode("\n");
        return "Hola. Se dio de baja {$empresa} en BigSys Web y ya apagamos la facturación electrónica que BigSys hacía en su nombre"
            . ' (CUIT ' . implode(', ', array_filter($cuits)) . ").\n\n"
            . "Para cerrar del todo, revocá también la autorización en ARCA (es un trámite tuyo, con tu clave fiscal):\n{$p}\n\n"
            . "Si después vas a facturar con otro sistema, esto no afecta tus puntos de venta ni tu numeración.\n\n— BigSys Web";
    }

    public static function vigentes(Business $b, ?BusinessLocation $loc)
    {
        return ArcaAutorizacion::withoutGlobalScopes()->where('business_id', $b->id)->where('sucursal_id', $loc?->id)->whereNull('revocada_en')->get();
    }

    public static function ultima(Business $b, ?BusinessLocation $loc = null): ?ArcaAutorizacion
    {
        return ArcaAutorizacion::withoutGlobalScopes()->where('business_id', $b->id)->where('sucursal_id', $loc?->id)->latest('id')->first();
    }

    private static function persona(User $u): string { return mb_substr(trim("{$u->name} <{$u->email}>"), 0, 150); }
}
