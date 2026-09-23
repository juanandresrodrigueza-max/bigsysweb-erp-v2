<?php

namespace App\Services\Fiscal;

use App\Models\Business;
use App\Models\Contact;
use App\Models\PadronIibb;

// Reglas de percepciones y retenciones de la empresa: padrones por CUIT, valores por cliente y defaults configurables.
class ImpuestosService
{
    public const DEFAULT = [
        'percepcion_iibb' => ['activo' => false, 'jurisdiccion' => 'ARBA', 'alicuota' => 3.0, 'minimo' => 0, 'solo_padron' => false],
        'retencion_iibb' => ['activo' => true, 'jurisdiccion' => 'ARBA', 'alicuota' => 3.0, 'minimo' => 0],
        'retencion_ganancias' => ['activo' => true, 'alicuota_bienes' => 2.0, 'alicuota_servicios' => 6.0, 'minimo' => 224000, 'acumula_mes' => true],
        'retencion_iva' => ['activo' => false, 'alicuota' => 10.5, 'minimo' => 0],
        'percepcion_iva' => ['activo' => false, 'alicuota' => 3.0, 'minimo' => 0, 'solo_ri' => true],           // RG 2408: sobre el neto gravado a responsables inscriptos
        'percepcion_ganancias' => ['activo' => false, 'alicuota' => 2.0, 'minimo' => 0],                        // regímenes de percepción de Ganancias (según actividad)
        'agente_retencion' => false,
        'agente_percepcion' => false,
    ];

    public function config(Business $b): array
    {
        $c = $b->impuestos ?? [];
        $out = self::DEFAULT;
        foreach ($out as $k => $v) $out[$k] = is_array($v) ? array_replace($v, $c[$k] ?? []) : ($c[$k] ?? $v);
        return $out;
    }

    // Percepción de IIBB a aplicar en una factura de venta: [alicuota, jurisdiccion] o null.
    public function percepcionIibb(Business $b, ?Contact $cli): ?array
    {
        if (! $cli || $cli->exento_iibb) return null;
        $cfg = $this->config($b)['percepcion_iibb'];
        $jur = $cli->jurisdiccion_iibb ?: $cfg['jurisdiccion'];
        if ($cli->alicuota_percepcion_iibb !== null && (float) $cli->alicuota_percepcion_iibb > 0) return ['alicuota' => (float) $cli->alicuota_percepcion_iibb, 'jurisdiccion' => $jur, 'origen' => 'cliente'];
        if ($p = PadronIibb::buscar($cli->cuit, $jur)) return (float) $p->alic_percepcion > 0 ? ['alicuota' => (float) $p->alic_percepcion, 'jurisdiccion' => $jur, 'origen' => 'padron'] : null;
        if ($cfg['activo'] && $cli->percepcion_iibb && ! $cfg['solo_padron']) return ['alicuota' => (float) $cfg['alicuota'], 'jurisdiccion' => $jur, 'origen' => 'default'];
        return null;
    }

    // Percepción de IVA en una factura de venta (agente de percepción, RG 2408): solo a responsables inscriptos marcados en su ficha.
    public function percepcionIva(Business $b, ?Contact $cli): ?array
    {
        $cfg = $this->config($b)['percepcion_iva'];
        if (! $cfg['activo'] || ! $cli || ! $cli->percepcion_iva) return null;
        if ($cfg['solo_ri'] && $cli->condicion_iva !== 'Responsable Inscripto') return null;
        return ['alicuota' => (float) $cfg['alicuota'], 'minimo' => (float) $cfg['minimo']];
    }

    public function percepcionGanancias(Business $b, ?Contact $cli): ?array
    {
        $cfg = $this->config($b)['percepcion_ganancias'];
        if (! $cfg['activo'] || ! $cli || ! $cli->percepcion_ganancias || $cli->condicion_iva === 'Monotributista') return null;
        return ['alicuota' => (float) $cfg['alicuota'], 'minimo' => (float) $cfg['minimo']];
    }

    // Retención sugerida al pagar a un proveedor: tipo iibb | ganancias | iva.
    public function retencionSugerida(Business $b, Contact $prov, string $tipo, float $base, float $acumuladoMes = 0): ?array
    {
        $cfg = $this->config($b);
        if ($tipo === 'iibb') {
            $c = $cfg['retencion_iibb'];
            if ($prov->exento_iibb) return ['alicuota' => 0, 'monto' => 0, 'motivo' => 'Proveedor exento de IIBB'];
            $jur = $prov->jurisdiccion_iibb ?: $c['jurisdiccion'];
            $al = $prov->alicuota_retencion_iibb !== null && (float) $prov->alicuota_retencion_iibb > 0 ? (float) $prov->alicuota_retencion_iibb : (($p = PadronIibb::buscar($prov->cuit, $jur)) ? (float) $p->alic_retencion : ($c['activo'] ? (float) $c['alicuota'] : 0));
            if ($base < (float) $c['minimo']) return ['alicuota' => $al, 'monto' => 0, 'motivo' => 'No supera el mínimo de $ ' . number_format((float) $c['minimo'], 0, ',', '.')];
            return ['alicuota' => $al, 'monto' => round($base * $al / 100, 2), 'jurisdiccion' => $jur, 'motivo' => $p ?? null ? 'Según padrón' : 'Alícuota configurada'];
        }
        if ($tipo === 'ganancias') {
            $c = $cfg['retencion_ganancias'];
            if (! $c['activo'] || ! $prov->retiene_ganancias || $prov->condicion_iva === 'Monotributista') return ['alicuota' => 0, 'monto' => 0, 'motivo' => 'No corresponde retener Ganancias'];
            $acum = $c['acumula_mes'] ? $acumuladoMes + $base : $base;
            if ($acum <= (float) $c['minimo']) return ['alicuota' => (float) $c['alicuota_bienes'], 'monto' => 0, 'motivo' => 'No supera el mínimo mensual de $ ' . number_format((float) $c['minimo'], 0, ',', '.')];
            $sujeto = $c['acumula_mes'] ? max(0, $acum - (float) $c['minimo']) - max(0, $acumuladoMes - (float) $c['minimo']) : $base - (float) $c['minimo'];
            return ['alicuota' => (float) $c['alicuota_bienes'], 'monto' => round(max(0, $sujeto) * (float) $c['alicuota_bienes'] / 100, 2), 'motivo' => 'RG 830: excedente del mínimo mensual'];
        }
        if ($tipo === 'iva') {
            $c = $cfg['retencion_iva'];
            if (! $c['activo'] || $prov->condicion_iva !== 'Responsable Inscripto') return ['alicuota' => 0, 'monto' => 0, 'motivo' => 'No corresponde retener IVA'];
            return ['alicuota' => (float) $c['alicuota'], 'monto' => round($base * (float) $c['alicuota'] / 100, 2), 'motivo' => 'Sobre el IVA de la factura'];
        }
        return null;
    }
}
