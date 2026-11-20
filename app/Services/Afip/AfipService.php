<?php

namespace App\Services\Afip;

use Afip;
use App\Models\Business;

// Puente con los web services de ARCA. Desde la fase 121, la facturación (WSFEv1) va por la conexión directa
// (ArcaDirecto), igual que en el CRM, con dos orígenes posibles del certificado:
//  · «bigsys»: la empresa le delegó el servicio a BigSys en ARCA y se factura con el certificado de BigSys (Panel → Sistema).
//  · «propio»: la empresa cargó su propio certificado (como antes).
// El padrón y la exportación (WSFEX) siguen por la librería y sólo están con certificado propio.
class AfipService
{
    private ?Afip $afip = null;
    private ?Wsfex $wsfex = null;
    private ArcaDirecto $directo;
    private string $cuit;
    public readonly string $modo;

    public function __construct(private Business $business)
    {
        $this->cuit = preg_replace('/\D/', '', (string) $business->cuit);
        if ($business->afip_cert_path && $business->afip_key_path) {
            $this->modo = 'propio';
            $cert = CertificadoCifrado::leer($business->afip_cert_path); $key = CertificadoCifrado::leer($business->afip_key_path);
            if (! $cert || ! $key) throw new ArcaError('No se encontró el certificado de ARCA cargado para esta empresa: volvé a subirlo en Configuración → Puntos de venta y ARCA.');
            $this->directo = new ArcaDirecto($cert, $key, (bool) $business->afip_produccion);
        } elseif ($business->arca_bigsys) {
            $this->modo = 'bigsys';
            $this->directo = ArcaPlataforma::cliente();
        } else {
            throw new ArcaError('Esta empresa no tiene configurada la factura electrónica (Configuración → Puntos de venta y ARCA).');
        }
    }

    // ─── Facturación (WSFEv1, conexión directa) ───
    public function getLastVoucher(int $puntoVenta, int $tipoComprobante): int { return $this->directo->ultimoAutorizado($this->cuit, $puntoVenta, $tipoComprobante); }
    public function createVoucher(array $data): array { return $this->directo->solicitarCae($this->cuit, $data); }
    public function getVoucherInfo(int $numero, int $puntoVenta, int $tipoComprobante): ?array { return $this->directo->consultar($this->cuit, $puntoVenta, $tipoComprobante, $numero); }
    public function getServerStatus(): array { return $this->directo->dummy(); }
    public function puntosVenta(): array { return $this->directo->puntosVenta($this->cuit); }
    public function produccion(): bool { return $this->directo->produccion(); }

    // ─── Con certificado propio: librería para padrón y exportación ───
    private function libreria(): Afip
    {
        if ($this->modo !== 'propio') throw new ArcaError('Esto todavía necesita el certificado propio de la empresa: con la facturación por BigSys sólo están las facturas, notas y la numeración.');
        return $this->afip ??= new Afip([
            'CUIT' => (int) $this->cuit,
            'cert' => CertificadoCifrado::rutaLegible($this->business->afip_cert_path),
            'key' => CertificadoCifrado::rutaLegible($this->business->afip_key_path),
            'production' => (bool) $this->business->afip_produccion,
        ]);
    }

    private function wsfex(): Wsfex { return $this->wsfex ??= new Wsfex($this->libreria(), (bool) $this->business->afip_produccion); }
    public function fexGetLastVoucher(int $pv, int $tipo): int { return $this->wsfex()->ultimoComprobante($pv, $tipo); }
    public function fexGetLastId(): int { return $this->wsfex()->ultimoId(); }
    public function fexAuthorize(array $cmp): array { return $this->wsfex()->autorizar($cmp); }
    public function fexGetVoucherInfo(int $pv, int $tipo, int $nro): ?array { return $this->wsfex()->consultar($pv, $tipo, $nro); }

    // Consulta de padrón: razón social, domicilio y condición frente al IVA de un CUIT (ws_sr_padron_a13 + a5).
    public function padron(string $cuit): ?array
    {
        $afip = $this->libreria();
        $id = (int) preg_replace('/\D/', '', $cuit);
        $p = $afip->RegisterScopeThirteen->GetTaxpayerDetails($id);
        if (! $p) return null;
        $p = json_decode(json_encode($p), true);
        $dom = collect($p['domicilio'] ?? [])->first(fn($d) => ($d['tipoDomicilio'] ?? '') === 'FISCAL') ?? (($p['domicilio'] ?? [])[0] ?? []);
        $nombre = $p['razonSocial'] ?? trim(($p['apellido'] ?? '') . ' ' . ($p['nombre'] ?? ''));
        $condicion = 'Consumidor Final';
        try {
            $a5 = json_decode(json_encode($afip->RegisterScopeFive->GetTaxpayerDetails($id)), true);
            $imp = collect($a5['datosRegimenGeneral']['impuesto'] ?? [])->pluck('idImpuesto')->all();
            if (! empty($a5['datosMonotributo'])) $condicion = 'Monotributista';
            elseif (in_array(30, $imp, true)) $condicion = 'Responsable Inscripto';
            elseif (in_array(32, $imp, true)) $condicion = 'Exento';
        } catch (\Throwable) { /* el padrón a5 puede no estar autorizado: se informa igual lo básico */ }
        return [
            'cuit' => \App\Support\Cuit::formatear((string) $id), 'nombre' => $nombre, 'condicion_iva' => $condicion,
            'direccion' => trim((string) ($dom['direccion'] ?? '')),
            'localidad' => $dom['localidad'] ?? null, 'provincia' => $dom['descripcionProvincia'] ?? null, 'codigo_postal' => $dom['codPostal'] ?? null,
            'estado' => $p['estadoClave'] ?? null,
        ];
    }

    // Punto único de creación: en pruebas se reemplaza por un doble (app()->instance('afip.fake', ...)).
    public static function forBusiness(Business $business): object
    {
        if (app()->bound('afip.fake')) { $f = app('afip.fake'); if (property_exists($f, 'emisor')) $f->emisor = $business; return $f; }
        return new static($business);
    }
}
