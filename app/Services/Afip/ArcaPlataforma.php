<?php

namespace App\Services\Afip;

use App\Models\ArcaTicket;
use App\Models\Business;
use App\Models\SistemaConfig;

// Fase 121: el certificado de BigSys para facturar en nombre de las empresas (como el CRM). Es UNO SOLO, de BigSys, y
// cada empresa le delega el servicio de Facturación Electrónica desde su Administrador de Relaciones de Clave Fiscal: la
// empresa no genera, sube ni descarga ningún certificado. Se carga una vez en Panel → Sistema.
//
// El ERP conviene que tenga SU PROPIO certificado de BigSys (otro alias del mismo CUIT): ARCA entrega un solo ticket de
// acceso vigente por certificado, y si el ERP y el CRM usan el mismo, se lo pisan y uno de los dos se queda sin facturar
// hasta 12 horas. Las delegaciones de las empresas son al CUIT de BigSys, así que sirven para los dos certificados.
class ArcaPlataforma
{
    public static function config(): ?array
    {
        $cert = SistemaConfig::get('arca_cert'); $key = SistemaConfig::get('arca_key');
        if (! $cert || ! $key) return null;
        return ['cert' => $cert, 'key' => $key, 'produccion' => (bool) SistemaConfig::get('arca_produccion')];
    }

    public static function configurada(): bool { try { return (bool) self::config(); } catch (\Throwable $e) { return false; } }

    public static function cliente(): ArcaDirecto
    {
        $c = self::config();
        if (! $c) throw new ArcaError('La facturación por BigSys todavía no está configurada: falta el certificado de BigSys en Panel → Sistema → Facturación electrónica.');
        return new ArcaDirecto($c['cert'], $c['key'], $c['produccion'], true);
    }

    // Lo que dice el certificado: a nombre de quién, qué CUIT, qué alias, de qué ambiente y hasta cuándo vale.
    public static function leerCertificado(string $pem): ?array
    {
        $x = @openssl_x509_parse($pem);
        if (! $x) return null;
        $sub = $x['subject'] ?? []; $iss = $x['issuer'] ?? [];
        $cuit = preg_replace('/\D/', '', (string) ($sub['serialNumber'] ?? ''));
        $emisor = (string) ($iss['CN'] ?? $iss['O'] ?? '');
        return ['alias' => $sub['CN'] ?? null, 'razon' => $sub['O'] ?? null, 'cuit' => strlen($cuit) === 11 ? $cuit : null,
            'desde' => \Carbon\Carbon::createFromTimestamp($x['validFrom_time_t']), 'vence' => \Carbon\Carbon::createFromTimestamp($x['validTo_time_t']),
            'emisor' => $emisor, 'de_prueba' => (bool) preg_match('/test|homo|prueba/i', $emisor)];
    }

    public static function estado(): array
    {
        $cert = SistemaConfig::get('arca_cert'); $info = $cert ? self::leerCertificado($cert) : null;
        $pedido = SistemaConfig::get('arca_pedido');
        return [
            'lista' => self::configurada(), 'produccion' => (bool) SistemaConfig::get('arca_produccion'),
            'alias' => $info['alias'] ?? null, 'cuit' => ! empty($info['cuit']) ? \App\Support\Cuit::formatear($info['cuit']) : null, 'razon' => SistemaConfig::get('arca_razon') ?: ($info['razon'] ?? null),
            'vence' => $info ? $info['vence']->format('d/m/Y') : null, 'vencido' => $info ? $info['vence']->isPast() : false, 'vence_pronto' => $info ? $info['vence']->lt(now()->addDays(30)) : false,
            'emisor' => $info['emisor'] ?? null, 'de_prueba' => $info['de_prueba'] ?? null,
            'pedido' => $pedido ? ['csr' => $pedido, 'alias' => SistemaConfig::get('arca_pedido_alias')] : null,
            'empresas' => Business::where('arca_bigsys', true)->count(),
        ];
    }

    // Representante para la guía de las empresas: a quién tienen que delegarle el servicio en ARCA.
    public static function representante(): ?array
    {
        $cert = SistemaConfig::get('arca_cert'); $info = $cert ? self::leerCertificado($cert) : null;
        if (! $info || ! $info['cuit']) return null;
        return ['cuit' => \App\Support\Cuit::formatear($info['cuit']), 'razon' => SistemaConfig::get('arca_razon') ?: ($info['razon'] ?? 'BigSys')];
    }

    // Genera la clave privada EN EL SERVIDOR y el pedido de certificado (CSR) para subir a ARCA. La clave no sale nunca de acá.
    public static function generarPedido(string $cuit, string $razon, string $alias): string
    {
        $cuit = preg_replace('/\D/', '', $cuit);
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['countryName' => 'AR', 'organizationName' => $razon, 'commonName' => $alias, 'serialNumber' => "CUIT {$cuit}"], $k, ['digest_alg' => 'sha256']);
        if (! $k || ! $csr) throw new ArcaError('No se pudo generar el pedido de certificado en el servidor.');
        openssl_pkey_export($k, $keyPem); openssl_csr_export($csr, $csrPem);
        SistemaConfig::set('arca_pedido_key', $keyPem);
        SistemaConfig::set('arca_pedido', $csrPem);
        SistemaConfig::set('arca_pedido_alias', $alias);
        SistemaConfig::set('arca_razon', $razon);
        return $csrPem;
    }

    // Carga el certificado que devolvió ARCA. Sin clave, usa la del pedido generado acá; con clave (el de otro sistema), esa.
    public static function cargar(string $certPem, ?string $keyPem = null, ?bool $produccion = null): array
    {
        $certPem = trim($certPem);
        if (! str_contains($certPem, 'BEGIN CERTIFICATE')) {
            // ARCA a veces entrega el .crt en DER (binario): se pasa a PEM.
            $der = $certPem; $certPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----";
        }
        $info = self::leerCertificado($certPem);
        if (! $info) throw new ArcaError('Eso no parece un certificado válido (tiene que ser el .crt que entrega ARCA).');
        $key = $keyPem ? trim($keyPem) : SistemaConfig::get('arca_pedido_key');
        if (! $key) throw new ArcaError('Falta la clave privada: generá primero el pedido de certificado acá, o subí la clave junto con el certificado.');
        if (! @openssl_x509_check_private_key($certPem, $key)) throw new ArcaError($keyPem ? 'El certificado y la clave no son pareja.' : 'Este certificado no corresponde al último pedido generado acá. Si es el de otro sistema, subí también su clave privada.');
        if ($info['vence']->isPast()) throw new ArcaError('El certificado está vencido (venció el ' . $info['vence']->format('d/m/Y') . ').');
        SistemaConfig::set('arca_cert', $certPem);
        SistemaConfig::set('arca_key', $key);
        SistemaConfig::set('arca_produccion', $produccion ?? ! $info['de_prueba']);
        if (! $keyPem) { SistemaConfig::set('arca_pedido_key', null); SistemaConfig::set('arca_pedido', null); }
        if ($info['razon'] && ! SistemaConfig::get('arca_razon')) SistemaConfig::set('arca_razon', $info['razon']);
        return $info;
    }

    public static function borrar(): void
    {
        foreach (['arca_cert', 'arca_key'] as $k) SistemaConfig::set($k, null);
    }

    // «Probar»: los servidores de ARCA contestan y el certificado consigue su ticket de acceso.
    public static function probar(): array
    {
        $pasos = [];
        try {
            $c = self::cliente();
            try { $d = $c->dummy(); $pasos[] = ['nombre' => 'Servidores de ARCA (' . ($c->produccion() ? 'producción' : 'homologación') . ')', 'ok' => $d['AppServer'] === 'OK' && $d['DbServer'] === 'OK' && $d['AuthServer'] === 'OK', 'detalle' => "App {$d['AppServer']} · DB {$d['DbServer']} · Auth {$d['AuthServer']}"]; }
            catch (\Throwable $e) { $pasos[] = ['nombre' => 'Servidores de ARCA', 'ok' => false, 'detalle' => $e->getMessage()]; }
            try { $t = $c->ticket('wsfe'); $pasos[] = ['nombre' => 'Certificado de BigSys (ticket de acceso a Facturación Electrónica)', 'ok' => true, 'detalle' => 'ARCA lo aceptó. Ticket vigente hasta ' . $t['expira']->timezone(config('app.timezone'))->format('d/m H:i') . '.']; }
            catch (\Throwable $e) { $pasos[] = ['nombre' => 'Certificado de BigSys (ticket de acceso)', 'ok' => false, 'detalle' => $e->getMessage()]; }
        } catch (\Throwable $e) { $pasos[] = ['nombre' => 'Configuración', 'ok' => false, 'detalle' => $e->getMessage()]; }
        return ['ok' => collect($pasos)->every(fn($p) => $p['ok']), 'pasos' => $pasos];
    }

    // Los tickets vencidos no sirven para nada: se borran (al cargar un certificado nuevo y en la limpieza de cada día).
    public static function limpiarTickets(): int { return ArcaTicket::where('expira', '<', now())->delete(); }
}
