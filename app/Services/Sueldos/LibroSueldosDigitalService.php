<?php

namespace App\Services\Sueldos;

use App\Models\Liquidacion;

// Fase 98: el archivo de importación del Libro de Sueldos Digital de ARCA (registros 01 a 04, ancho fijo).
// Los códigos de concepto son los de la empresa (como los declaró en la solapa Conceptos del LSD); las bases y los datos de revista salen
// de la liquidación y de la ficha del empleado. Importes sin separador, con dos decimales implícitos y ceros a la izquierda.
class LibroSueldosDigitalService
{
    public const TIPOS_LIQ = ['mensual' => 'M', 'sac' => 'S', 'final' => 'F'];
    public const SITUACIONES = ['01' => 'Activo', '05' => 'Licencia por maternidad', '09' => 'Suspendido', '10' => 'Licencia sin goce de haberes', '11' => 'Licencia por maternidad down', '12' => 'Licencia por excedencia', '13' => 'Vacaciones', '18' => 'Accidente de trabajo', '19' => 'Reserva de puesto', '21' => 'Incapacidad laboral permanente'];
    public const CONDICIONES = ['01' => 'Servicios comunes mayor de 18 años', '02' => 'Jubilado', '03' => 'Menor de 18 años', '04' => 'Servicios diferenciados', '08' => 'Pasante', '15' => 'Trabajador agrario'];
    public const MODALIDADES = ['008' => 'A tiempo completo indeterminado', '001' => 'A tiempo parcial indeterminado', '010' => 'Período de prueba', '021' => 'A plazo fijo tiempo completo', '027' => 'Pasantía', '102' => 'Eventual', '999' => 'Otra'];

    public function defaults(array $cfg): array
    {
        return ['condicion' => $cfg['lsd_condicion'] ?? '01', 'situacion' => $cfg['lsd_situacion'] ?? '01', 'modalidad_contratacion' => $cfg['lsd_modalidad'] ?? '008', 'actividad' => $cfg['lsd_actividad'] ?? '049', 'zona' => $cfg['lsd_zona'] ?? '00', 'siniestrado' => '00', 'adherentes' => 0];
    }

    public function generar(Liquidacion $liq, array $cfg = []): string
    {
        $b = $liq->business; $cuit = preg_replace('/\D/', '', (string) $b->cuit);
        $per = str_replace('-', '', $liq->periodo);
        $items = $liq->items()->with('empleado')->get();
        $l = [];
        // 01 · Datos referenciales de la liquidación
        $l[] = '01' . self::n($cuit, 11) . 'SJ' . $per . (self::TIPOS_LIQ[$liq->tipo] ?? 'M') . self::n($liq->id, 5) . self::n(30, 2) . self::n($items->count(), 6);
        foreach ($items as $i) {
            $e = $i->empleado; $cuil = preg_replace('/\D/', '', (string) $e->cuil); $lsd = $this->defaults($cfg); foreach ((array) ($e->lsd ?? []) as $k => $v) if ($v !== null && $v !== '') $lsd[$k] = $v;
            // 02 · Datos del empleado
            $l[] = '02' . self::n($cuil, 11) . self::t($e->legajo, 10) . self::t($e->lugar_trabajo ?: $b->razon_social, 50) . self::t(preg_replace('/\D/', '', (string) $e->cbu), 22) . self::n($liq->tipo === 'sac' ? min(180, $i->dias) : 0, 3) . self::t('', 10) . self::t($e->centro_costo, 50);
            // 03 · Conceptos liquidados (los de la empresa, con su código)
            foreach ((array) $i->detalle as $c) {
                if (($c['tipo'] ?? '') === 'contribucion') continue; // las contribuciones no van al libro: salen del F.931
                $deb = ($c['tipo'] ?? '') === 'deduccion' ? 'D' : 'C';
                $cant = (float) ($c['cantidad'] ?? 0); $unidad = match ($c['unidad'] ?? null) { 'dias', 'd' => 'D', 'horas', 'h' => 'H', default => (str_starts_with((string) ($c['codigo'] ?? ''), 'HE') ? 'H' : ($cant ? 'D' : ' ')) };
                $l[] = '03' . self::n($cuil, 11) . self::t($c['codigo'] ?? '', 10) . self::n(round($cant, 0), 5) . $unidad . self::imp($c['monto'] ?? 0, 15) . $deb . self::t('', 6);
            }
            // 04 · Datos complementarios (los del F.931)
            $rem = (float) $i->bruto; $noRem = (float) $i->no_rem; $aportes = collect($i->detalle ?? [])->where('tipo', 'deduccion')->whereNotIn('codigo', ['ANTI', 'GAN'])->sum('monto');
            $l[] = '04' . self::n($cuil, 11) . self::n($lsd['condicion'], 2) . self::n($lsd['situacion'], 2) . self::n($lsd['actividad'], 3) . self::n($lsd['zona'], 2) . self::n($lsd['modalidad_contratacion'], 3) . self::n($lsd['siniestrado'], 2) . self::t($e->localidad ?: $e->lugar_trabajo, 50) . self::n($lsd['adherentes'], 2)
                . self::imp(0, 15) . self::imp($rem + $noRem, 15) // rem. maternidad ANSES, remuneración bruta
                . str_repeat(self::imp($rem, 15), 10)             // bases imponibles 1 a 10 (sin topes: los aplica el LSD)
                . self::imp(0, 15) . self::imp($liq->tipo === 'sac' ? $rem : 0, 15) // base diferencial, base SAC
                . self::imp(0, 15) . self::imp(0, 15) . self::imp(0, 15) // adicional OS, excedente aportes SS, excedente aportes OS
                . self::n($i->dias, 2) . self::n(0, 3) . self::imp($aportes, 15) . self::imp($noRem, 15) . self::n(0, 2) . self::n($i->vacaciones ?? 0, 2);
        }
        return implode("\r\n", $l) . "\r\n";
    }

    public function nombreArchivo(Liquidacion $liq): string { return 'LSD_' . preg_replace('/\D/', '', (string) $liq->business->cuit) . '_' . str_replace('-', '', $liq->periodo) . '_' . strtoupper($liq->tipo) . '.txt'; }

    // Resumen para el contador y para Bigcito.
    public function resumen(Liquidacion $liq): array
    {
        $items = $liq->items()->with('empleado')->get();
        return ['periodo' => $liq->periodo, 'tipo' => $liq->tipo, 'empleados' => $items->count(), 'conceptos' => $items->sum(fn($i) => collect($i->detalle ?? [])->where('tipo', '!=', 'contribucion')->count()), 'remuneracion_bruta' => round((float) $items->sum('bruto'), 2), 'no_remunerativo' => round((float) $items->sum('no_rem'), 2), 'sin_cuil' => $items->filter(fn($i) => strlen(preg_replace('/\D/', '', (string) $i->empleado->cuil)) !== 11)->map(fn($i) => $i->empleado->nombre)->values()->all()];
    }

    private static function n($v, int $w): string { $s = preg_replace('/\D/', '', (string) $v); return str_pad(substr($s, -$w), $w, '0', STR_PAD_LEFT); }
    private static function t($v, int $w): string { $s = mb_substr(self::sinAcentos((string) $v), 0, $w); return str_pad($s, $w, ' ', STR_PAD_RIGHT); }
    private static function imp($v, int $w): string { return str_pad((string) (int) round(abs((float) $v) * 100), $w, '0', STR_PAD_LEFT); }
    private static function sinAcentos(string $s): string { return strtoupper(strtr($s, ['á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'ñ' => 'N', 'Ñ' => 'N', 'ü' => 'U', 'Ü' => 'U'])); }
}
