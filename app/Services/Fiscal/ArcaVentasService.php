<?php

namespace App\Services\Fiscal;

use App\Models\Comprobante;
use App\Models\Contact;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Support\Facades\Auth;

// Cruza "Mis Comprobantes Emitidos" de ARCA con lo facturado en el sistema y registra lo que falte (facturado por fuera).
class ArcaVentasService
{
    public function __construct(private ComprobanteService $comprobantes) {}

    public function analizar(string $contenido): array
    {
        $filas = $this->leer($contenido);
        $enSistema = Comprobante::ventas()->fiscales()->where('estado', '!=', 'borrador')->get()->keyBy(fn($c) => ($c->afipTipo() ?? 0) . '-' . (int) $c->punto_venta . '-' . (int) $c->numero);
        $faltan = []; $ok = 0;
        foreach ($filas as $f) {
            $k = $f['tipo'] . '-' . $f['pv'] . '-' . $f['numero'];
            if (isset($enSistema[$k])) { $ok++; unset($enSistema[$k]); continue; }
            $faltan[] = $f;
        }
        $sobran = $enSistema->filter(fn($c) => $c->esFiscal() && $c->estado === 'emitido' && $c->afip_estado !== 'simulado')->values()->map(fn($c) => ['tipo' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'fecha' => $c->fecha->format('d/m/Y'), 'total' => (float) $c->total, 'id' => $c->id]);
        return ['leidas' => count($filas), 'coinciden' => $ok, 'faltan' => $faltan, 'sobran' => $sobran->all()];
    }

    // Registra en el sistema los comprobantes emitidos afuera (ya tienen CAE): impactan cuenta corriente, no stock.
    public function registrar(array $filas): int
    {
        $n = 0;
        foreach ($filas as $f) {
            $tipo = $this->tipoDesdeAfip((int) $f['tipo']);
            if (! $tipo) continue;
            $cli = $f['cuit'] ? Contact::customers()->where('cuit', 'like', '%' . substr($f['cuit'], 0, 2) . '%' . substr($f['cuit'], 2, 8) . '%')->first() : null;
            $cli ??= $f['cuit'] ? Contact::create(['business_id' => Auth::user()->business_id, 'type' => 'customer', 'name' => $f['nombre'] ?: "CUIT {$f['cuit']}", 'cuit' => substr($f['cuit'], 0, 2) . '-' . substr($f['cuit'], 2, 8) . '-' . substr($f['cuit'], 10), 'condicion_iva' => in_array((int) $f['tipo'], [1, 2, 3, 201, 202, 203], true) ? 'Responsable Inscripto' : 'Consumidor Final', 'is_active' => true, 'lista_precios' => 1]) : null;
            $c = $this->comprobantes->guardarBorrador(['contact_id' => $cli?->id, 'tipo' => $tipo, 'fecha' => $f['fecha'], 'condicion' => 'cta_cte', 'notas' => 'Registrado desde Mis Comprobantes ARCA (emitido por fuera del sistema)', 'items' => [['descripcion' => 'Comprobante emitido externamente', 'cantidad' => 1, 'precio_unit' => $f['neto'], 'descuento' => 0, 'alicuota_iva' => $f['neto'] > 0 ? round($f['iva'] / $f['neto'] * 100, 1) : 0]]]);
            $c->forceFill(['punto_venta' => $f['pv'], 'numero' => $f['numero'], 'estado' => 'emitido', 'emitido_en' => now(), 'afip_estado' => 'aprobado', 'cae' => $f['cae'] ?: null, 'origen_carga' => 'afip_csv', 'saldo' => $c->def()['cc'] > 0 ? $c->total : 0, 'fce' => (int) $f['tipo'] >= 201])->save();
            \App\Models\CuentaCorriente::create(['business_id' => $c->business_id, 'contact_id' => $c->contact_id, 'comprobante_id' => $c->id, 'fecha' => $c->fecha, 'fecha_vto' => $c->fecha_vto, 'tipo' => $c->def()['grupo'], 'concepto' => "{$c->nombreTipo()} {$c->numeroFormateado()} (ARCA)", 'debe' => $c->def()['cc'] > 0 ? $c->total : 0, 'haber' => $c->def()['cc'] < 0 ? $c->total : 0]);
            if ($c->contact_id) \App\Models\CuentaCorriente::recalcularSaldo($c->contact_id);
            app(\App\Services\Contabilidad\ContabilidadService::class)->contabilizar($c->fresh(['items', 'contact']));
            $n++;
        }
        return $n;
    }

    private function tipoDesdeAfip(int $t): ?string
    {
        $map = [1 => 'FA', 6 => 'FB', 11 => 'FC', 19 => 'FE', 2 => 'NDA', 7 => 'NDB', 12 => 'NDC', 3 => 'NCA', 8 => 'NCB', 13 => 'NCC', 201 => 'FA', 206 => 'FB', 211 => 'FC', 202 => 'NDA', 207 => 'NDB', 212 => 'NDC', 203 => 'NCA', 208 => 'NCB', 213 => 'NCC'];
        return $map[$t] ?? null;
    }

    // CSV de "Mis Comprobantes Emitidos": columnas con nombres, separador ; o ,.
    private function leer(string $raw): array
    {
        if (! mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $lineas = array_values(array_filter(preg_split('/\r\n|\r|\n/', $raw), fn($l) => trim($l) !== ''));
        if (count($lineas) < 2) return [];
        $sep = substr_count($lineas[0], ';') >= substr_count($lineas[0], ',') ? ';' : ',';
        $head = array_map(fn($h) => mb_strtolower(trim($h, " \"")), str_getcsv($lineas[0], $sep));
        $col = fn($needle) => (function () use ($head, $needle) { foreach ($head as $i => $h) foreach ((array) $needle as $nd) if (str_contains($h, $nd)) return $i; return null; })();
        $iFecha = $col('fecha'); $iTipo = $col(['tipo de comprobante', 'tipo comprobante', 'tipo']); $iPv = $col(['punto de venta', 'punto']); $iNum = $col(['número desde', 'numero desde', 'nro. desde', 'número']); $iCuit = $col(['nro. doc. receptor', 'doc. receptor', 'nro doc', 'cuit']); $iNom = $col(['denominación receptor', 'denominacion receptor', 'receptor']); $iNeto = $col(['imp. neto gravado', 'neto gravado']); $iIva = $col(['total iva', 'iva']); $iTotal = $col(['imp. total', 'total']); $iCae = $col('cae');
        $num = fn($v) => (float) str_replace(',', '.', str_replace('.', '', trim((string) $v, " \"")));
        $out = [];
        foreach (array_slice($lineas, 1) as $l) {
            $f = str_getcsv($l, $sep);
            $tipo = (int) preg_replace('/\D.*$/', '', trim((string) ($f[$iTipo] ?? '')));
            if (! $tipo) continue;
            $fechaRaw = trim((string) ($f[$iFecha] ?? ''), " \"");
            $fecha = preg_match('/^\d{4}-\d{2}-\d{2}/', $fechaRaw) ? substr($fechaRaw, 0, 10) : (\DateTime::createFromFormat('d/m/Y', $fechaRaw)?->format('Y-m-d') ?? today()->toDateString());
            $out[] = ['tipo' => $tipo, 'pv' => (int) ($f[$iPv] ?? 0), 'numero' => (int) ($f[$iNum] ?? 0), 'fecha' => $fecha, 'cuit' => preg_replace('/\D/', '', (string) ($f[$iCuit] ?? '')), 'nombre' => trim((string) ($f[$iNom] ?? ''), " \""), 'neto' => $num($f[$iNeto] ?? 0), 'iva' => $num($f[$iIva] ?? 0), 'total' => $num($f[$iTotal] ?? 0), 'cae' => trim((string) ($f[$iCae] ?? ''), " \"")];
        }
        return $out;
    }
}
