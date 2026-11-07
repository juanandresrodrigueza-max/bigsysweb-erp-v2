<?php

namespace App\Services\Migracion;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

// Fase 41: migrar a un cliente de BigSys sin planillas. BIG Connect ya tiene sus datos leídos de los .TPS en el Hub;
// se piden por su API de sólo lectura (/api/v1/tabla/<nombre>, de a 5.000 filas) y entran por el mismo importador
// que los archivos, con el perfil BigSys. Primero una pasada de prueba que no guarda nada, después la de verdad;
// se puede repetir el día del corte: actualiza lo que ya está y no duplica.
class BigConnectService
{
    public const TOPE = 5000;
    // Lo que se trae, en el orden en que se carga (los saldos cruzan contra clientes y proveedores ya creados).
    public const PASOS = ['proveedores' => 'Proveedores', 'clientes' => 'Clientes', 'articulos' => 'Artículos', 'saldos_clientes' => 'Saldos de clientes', 'saldos_proveedores' => 'Saldos de proveedores'];

    public function __construct(private ImportadorService $importador) {}

    public function config(Business $b): array
    {
        $c = $b->bigconnect ?? [];
        return ['url' => $c['url'] ?? null, 'empresa' => $c['empresa'] ?? null, 'conectado' => ! empty($c['clave']), 'clave' => ! empty($c['clave']) ? substr($c['clave'], 0, 11) . '…' : null, 'ultima' => $c['ultima'] ?? null];
    }

    // Guarda la conexión y prueba que la clave sirva: devuelve qué empresas y tablas ve.
    public function conectar(Business $b, string $url, ?string $clave, ?string $empresa = null): array
    {
        $url = rtrim(preg_replace('#/api/v1/?$#', '', trim($url)), '/');
        if (! preg_match('#^https?://#', $url)) throw ValidationException::withMessages(['url' => 'La dirección del Hub empieza con https:// (la ves en BIG Connect → Importar y exportar → API).']);
        $c = $b->bigconnect ?? [];
        $clave = trim((string) $clave) ?: ($c['clave'] ?? '');
        if (! preg_match('/^bk_[A-Za-z0-9]{8}_[A-Za-z0-9]{28}$/', $clave)) throw ValidationException::withMessages(['clave' => 'La clave de BIG Connect empieza con bk_ (se genera en Importar y exportar → API).']);
        $r = $this->pedir($url, $clave, 'tablas', $empresa ? ['empresa' => $empresa] : []);
        $faltan = array_diff(['productos', 'clientes', 'proveedores', 'cuenta_movimientos'], array_column($r['tablas'] ?? [], 'nombre'));
        if ($faltan) throw ValidationException::withMessages(['clave' => 'La clave no puede leer: ' . implode(', ', $faltan) . '. Generá una clave con acceso completo.']);
        $b->forceFill(['bigconnect' => ['url' => $url, 'clave' => $clave, 'empresa' => $empresa ?: ($r['empresa'] ?? null), 'ultima' => $c['ultima'] ?? null]])->save();
        AuditLog::registrar('editar', $b, 'Conectó BIG Connect para migrar (' . parse_url($url, PHP_URL_HOST) . ')');
        return ['negocio' => $r['negocio'] ?? null, 'empresa' => $r['empresa'] ?? null, 'tablas' => count($r['tablas'] ?? [])];
    }

    // Una tabla entera, de a páginas. $max corta antes (para la vista previa).
    public function tabla(Business $b, string $tabla, array $filtros = [], ?int $max = null): array
    {
        $c = $b->bigconnect ?? []; if (empty($c['clave'])) throw ValidationException::withMessages(['clave' => 'Primero conectá BIG Connect.']);
        $out = []; $desde = 0;
        do {
            $lim = $max ? min(self::TOPE, $max - count($out)) : self::TOPE;
            $r = $this->pedir($c['url'], $c['clave'], 'tabla/' . $tabla, $filtros + ['limite' => $lim, 'desde' => $desde] + (! empty($c['empresa']) ? ['empresa' => $c['empresa']] : []));
            $filas = $r['filas'] ?? []; $cols = $r['columnas'] ?? null;
            // La API devuelve filas como objetos o como listas con 'columnas' aparte: se normaliza a asociativo.
            foreach ($filas as $f) $out[] = $cols && array_is_list($f) ? array_combine($cols, $f) : (array) $f;
            $desde += count($filas);
        } while (count($filas) === $lim && (! $max || count($out) < $max) && $desde < 500000);
        return $out;
    }

    // Arma, para cada paso, las filas y el mapeo que entiende el importador.
    public function preparar(Business $b): array
    {
        $vend = collect($this->tabla($b, 'vendedores'))->pluck('nombre', 'codven')->all();
        $cli = $this->tabla($b, 'clientes'); $prov = $this->tabla($b, 'proveedores'); $art = $this->tabla($b, 'productos');
        $movs = $this->tabla($b, 'cuenta_movimientos', ['saldo_pendiente__gt' => 0, 'anulado' => 0]);
        $iva = fn($x) => match (strtoupper(trim((string) $x))) { 'RI', 'R.I.' => 'Responsable Inscripto', 'MT', 'MO' => 'Monotributo', 'EXE', 'EX' => 'Exento', default => 'Consumidor Final' };
        $cuenta = fn($r, $tipo) => [(string) $r['codcta'], $tipo, $r['nombre'] ?? '', $r['cuit'] ?? '', $iva($r['condicion_iva'] ?? ''), $r['mail'] ?? '', $r['telefono'] ?? '', $r['direccion'] ?? '', $r['localidad'] ?? '', $r['provincia'] ?? ''];
        $mapCuenta = ['codigo', 'tipo_cuenta', 'nombre', 'cuit', 'condicion_iva', 'email', 'telefono', 'direccion', 'localidad', 'provincia'];
        $saldos = function ($tipo) use ($movs) {
            return collect($movs)->where('tipcta', $tipo)->map(fn($m) => [(string) $m['codcta'], trim(($m['tipcom'] ?? '') . ' ' . str_pad((string) ($m['pv'] ?? 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) ($m['nrocom'] ?? ''), 8, '0', STR_PAD_LEFT)), $m['fecha'] ?? '', $m['vencimiento'] ?: ($m['fecha'] ?? ''), (float) $m['saldo_pendiente']])->values()->all();
        };
        return [
            'proveedores' => ['filas' => array_map(fn($r) => $cuenta($r, 'P'), $prov), 'mapeo' => $mapCuenta],
            'clientes' => ['filas' => array_map(fn($r) => array_merge($cuenta($r, 'C'), [$r['lista_precios'] ?? '', $r['descuento'] ?? '', $vend[$r['vendedor'] ?? ''] ?? '', $r['limite_credito'] ?? '']), array_values(array_filter($cli, fn($r) => empty($r['contado'])))),
                'mapeo' => array_merge($mapCuenta, ['lista', 'descuento', 'vendedor', 'limite_credito'])],
            // Los precios del Hub ya son NETOS (sin IVA) y los artículos en dólares vienen pasados a pesos.
            'articulos' => ['filas' => array_map(fn($r) => [(string) $r['codart'], $r['codbar'] ?? '', $r['nombre'] ?? '', $r['rubro'] ?? '', $r['unidad'] ?? '', $r['costo'] ?? '', $r['precio1'] ?? '', $r['precio2'] ?? '', $r['precio3'] ?? '', $r['precio4'] ?? '', $r['precio5'] ?? '', $r['precio6'] ?? '', $r['iva_pct'] ?? 21, $r['stock'] ?? '', $r['stock_min'] ?? '', 'si'], $art),
                'mapeo' => ['codigo', 'barcode', 'descripcion', 'rubro', 'unidad', 'costo', 'precio1', 'precio2', 'precio3', 'precio4', 'precio5', 'precio6', 'iva', 'stock', 'stock_min', 'activo']],
            'saldos_clientes' => ['filas' => $saldos('C'), 'mapeo' => ['codigo', 'comprobante', 'fecha', 'vencimiento', 'importe']],
            'saldos_proveedores' => ['filas' => $saldos('P'), 'mapeo' => ['codigo', 'comprobante', 'fecha', 'vencimiento', 'importe']],
            'contado' => count($cli) - count(array_filter($cli, fn($r) => empty($r['contado']))),
        ];
    }

    // Trae todo. Con $prueba = true lo hace adentro de una transacción que se deshace: dice qué pasaría sin guardar nada.
    // Los saldos van por comprobante (lo impago de cada factura, con su vencimiento), no el saldo suelto de la cuenta.
    public function migrar(Business $b, bool $prueba, array $pasos = []): array
    {
        $datos = $this->preparar($b);
        $pasos = $pasos ?: array_keys(self::PASOS);
        $res = [];
        DB::beginTransaction();
        try {
            foreach (self::PASOS as $k => $label) {
                if (! in_array($k, $pasos, true)) continue;
                $imp = $this->importador->aplicar($k, $datos[$k]['filas'], $datos[$k]['mapeo'], ['archivo' => 'BIG Connect' . ($prueba ? ' (prueba)' : ''), 'saldos' => false, 'precios_con_iva' => false, 'ajustar_stock' => true, 'reemplazar_saldos' => true]);
                $res[] = ['paso' => $k, 'que' => $label, 'en_bigsys' => count($datos[$k]['filas']), 'creadas' => $imp->creadas, 'actualizadas' => $imp->actualizadas, 'errores' => $imp->errores, 'detalle' => array_slice((array) $imp->detalle, 0, 5)];
            }
            $control = ['clientes' => Contact::customers()->whereNotNull('codigo')->count(), 'proveedores' => Contact::suppliers()->whereNotNull('codigo')->count(), 'articulos' => Product::count()];
            if ($prueba) DB::rollBack(); else {
                DB::commit();
                $c = $b->bigconnect; $c['ultima'] = now()->format('d/m/Y H:i'); $b->forceFill(['bigconnect' => $c])->save();
                AuditLog::registrar('importar', $b, 'Migración desde BIG Connect: ' . collect($res)->map(fn($r) => "{$r['que']} {$r['creadas']}+{$r['actualizadas']}")->implode(', '));
            }
        } catch (\Throwable $e) { DB::rollBack(); throw $e; }
        return ['prueba' => $prueba, 'pasos' => $res, 'cuenta_contado' => $datos['contado'], 'control' => $control,
            'diferencias' => collect($res)->filter(fn($r) => $r['errores'] > 0)->map(fn($r) => "{$r['que']}: {$r['errores']} con error")->values()->all()];
    }

    private function pedir(string $url, string $clave, string $ruta, array $q = []): array
    {
        try { $r = Http::withToken($clave)->acceptJson()->timeout(120)->get($url . '/api/v1/' . $ruta, $q); }
        catch (\Throwable $e) { throw ValidationException::withMessages(['url' => 'No se pudo llegar al Hub de BIG Connect: ' . $e->getMessage()]); }
        if ($r->status() === 401 || $r->status() === 403) throw ValidationException::withMessages(['clave' => 'BIG Connect rechazó la clave: ' . ($r->json('error') ?? 'sin permiso')]);
        if (! $r->successful()) throw ValidationException::withMessages(['url' => 'BIG Connect respondió ' . $r->status() . ': ' . ($r->json('error') ?? 'error')]);
        return $r->json() ?? [];
    }
}
