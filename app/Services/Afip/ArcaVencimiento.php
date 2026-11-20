<?php

namespace App\Services\Afip;

use App\Models\SistemaConfig;

// Fase 123.3: el certificado de BigSys para ARCA vence (cada 2 años). Si vence, NINGUNA empresa factura por BigSys, así que se
// avisa con tiempo a todos los administradores de BigSys por mail, WhatsApp y celular (los mismos canales que las altas
// nuevas, Panel → Sistema → «Altas con aprobación»): a los 30 días, a los 7, el día anterior y si llegó a vencer. Cada aviso
// sale una sola vez por certificado; al cargar uno nuevo, la cuenta arranca de nuevo.
class ArcaVencimiento
{
    public const UMBRALES = [1, 7, 30]; // días antes del vencimiento

    public function revisar(): array
    {
        $cert = SistemaConfig::get('arca_cert'); $info = $cert ? ArcaPlataforma::leerCertificado($cert) : null;
        if (! $info) return ['aviso' => null, 'motivo' => 'Sin certificado de BigSys cargado.'];
        $dias = (int) now()->startOfDay()->diffInDays($info['vence']->copy()->startOfDay(), false);
        $umbral = $dias < 0 ? 'vencido' : collect(self::UMBRALES)->first(fn($u) => $dias <= $u);
        if ($umbral === null) return ['aviso' => null, 'motivo' => "Faltan {$dias} días."];
        $clave = substr(hash('sha256', preg_replace('/\s+/', '', $cert)), 0, 16) . ":{$umbral}";
        $hechos = (array) (SistemaConfig::get('arca_avisos_vencimiento') ?? []);
        if (isset($hechos[$clave])) return ['aviso' => null, 'motivo' => "Ya se avisó ({$umbral})."];

        $vence = $info['vence']->copy()->timezone(config('app.timezone'))->format('d/m/Y');
        $alias = $info['alias'] ?? '—';
        $asunto = $dias < 0 ? "URGENTE: venció el certificado de BigSys para ARCA ({$alias})"
            : ($dias <= 1 ? "Mañana vence el certificado de BigSys para ARCA ({$alias})" : "El certificado de BigSys para ARCA vence en {$dias} días ({$alias})");
        $texto = ($dias < 0 ? "Venció el {$vence}. Ninguna empresa puede facturar a través de BigSys hasta que se cargue uno nuevo."
                : "Vence el {$vence}. Si vence, ninguna empresa puede facturar a través de BigSys.")
            . "\n\nCómo renovarlo (15 minutos, con la clave fiscal de BigSys):"
            . "\n1. Panel → Sistema → Facturación electrónica → «Generar otro pedido» (mismo alias {$alias}) → bajar el .csr."
            . "\n2. ARCA → Administración de Certificados Digitales → BIGSYS S.R.L. → alias {$alias} → «Agregar certificado» → subir el .csr → descargar el .crt."
            . "\n3. Panel → Sistema → «Cargar el certificado» → subir el .crt (sin clave) → Probar."
            . "\nLa relación «Facturación Electrónica» del alias ya está hecha: no hay que repetirla."
            . "\nEl paso a paso está en el Manual de datos y restauración y en el panel.";
        $r = app(\App\Services\Suscripciones\AprobacionService::class)->avisar($asunto, $texto, '/admin/sistema');
        $hechos[$clave] = now()->toDateTimeString();
        SistemaConfig::set('arca_avisos_vencimiento', $hechos);
        \App\Models\AuditLog::registrar('editar', null, "Aviso de vencimiento del certificado de ARCA ({$umbral}): {$asunto}");
        return ['aviso' => $umbral, 'asunto' => $asunto, 'canales' => $r];
    }
}
