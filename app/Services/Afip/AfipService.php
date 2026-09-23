<?php

namespace App\Services\Afip;

use Afip;
use App\Models\Business;
use Illuminate\Support\Facades\Storage;

// Puente con los web services de ARCA/AFIP (WSFE para facturar, padrón para consultar CUIT). Los certificados se leen cifrados.
class AfipService
{
    private Afip $afip;
    private Business $business;
    private ?Wsfex $wsfex = null;

    public function __construct(Business $business)
    {
        $this->business = $business;
        $this->afip = new Afip([
            'CUIT'       => (int) preg_replace('/\D/', '', (string) $business->cuit),
            'cert'       => $this->resolvePath($business->afip_cert_path),
            'key'        => $this->resolvePath($business->afip_key_path),
            'production' => (bool) $business->afip_produccion,
        ]);
    }

    public function getLastVoucher(int $puntoVenta, int $tipoComprobante): int
    {
        return (int) $this->afip->ElectronicBilling->GetLastVoucher($puntoVenta, $tipoComprobante);
    }

    public function createVoucher(array $data): array
    {
        return (array) $this->afip->ElectronicBilling->CreateVoucher($data);
    }

    public function getVoucherInfo(int $numero, int $puntoVenta, int $tipoComprobante): ?array
    {
        $r = $this->afip->ElectronicBilling->GetVoucherInfo($numero, $puntoVenta, $tipoComprobante);
        return $r ? json_decode(json_encode($r), true) : null;
    }

    // --- Exportación (WSFEX) ---
    private function wsfex(): Wsfex { return $this->wsfex ??= new Wsfex($this->afip, (bool) $this->business->afip_produccion); }
    public function fexGetLastVoucher(int $pv, int $tipo): int { return $this->wsfex()->ultimoComprobante($pv, $tipo); }
    public function fexGetLastId(): int { return $this->wsfex()->ultimoId(); }
    public function fexAuthorize(array $cmp): array { return $this->wsfex()->autorizar($cmp); }
    public function fexGetVoucherInfo(int $pv, int $tipo, int $nro): ?array { return $this->wsfex()->consultar($pv, $tipo, $nro); }

    public function getServerStatus(): array
    {
        return json_decode(json_encode($this->afip->ElectronicBilling->GetServerStatus()), true) ?: [];
    }

    // Consulta de padrón: razón social, domicilio y condición frente al IVA de un CUIT (ws_sr_padron_a13 + a5).
    public function padron(string $cuit): ?array
    {
        $id = (int) preg_replace('/\D/', '', $cuit);
        $p = $this->afip->RegisterScopeThirteen->GetTaxpayerDetails($id);
        if (! $p) return null;
        $p = json_decode(json_encode($p), true);
        $dom = collect($p['domicilio'] ?? [])->first(fn($d) => ($d['tipoDomicilio'] ?? '') === 'FISCAL') ?? (($p['domicilio'] ?? [])[0] ?? []);
        $nombre = $p['razonSocial'] ?? trim(($p['apellido'] ?? '') . ' ' . ($p['nombre'] ?? ''));
        $condicion = 'Consumidor Final';
        try {
            $a5 = json_decode(json_encode($this->afip->RegisterScopeFive->GetTaxpayerDetails($id)), true);
            $imp = collect($a5['datosRegimenGeneral']['impuesto'] ?? [])->pluck('idImpuesto')->all();
            if (! empty($a5['datosMonotributo'])) $condicion = 'Monotributista';
            elseif (in_array(30, $imp, true)) $condicion = 'Responsable Inscripto';
            elseif (in_array(32, $imp, true)) $condicion = 'Exento';
        } catch (\Throwable) { /* el padrón a5 puede no estar autorizado: se informa igual lo básico */ }
        return [
            'cuit' => \App\Support\Cuit::formatear((string) $id), 'nombre' => $nombre, 'condicion_iva' => $condicion,
            'direccion' => trim(($dom['direccion'] ?? '') . ' ' . ($dom['descripcionProvincia'] ?? '' ? '' : '')),
            'localidad' => $dom['localidad'] ?? null, 'provincia' => $dom['descripcionProvincia'] ?? null, 'codigo_postal' => $dom['codPostal'] ?? null,
            'estado' => $p['estadoClave'] ?? null,
        ];
    }

    private function resolvePath(?string $path): string
    {
        if (! $path) return '';
        return CertificadoCifrado::rutaLegible($path);
    }

    // Punto único de creación: en pruebas se reemplaza por un doble (app()->instance('afip.fake', ...)).
    public static function forBusiness(Business $business): object
    {
        if (app()->bound('afip.fake')) { $f = app('afip.fake'); if (property_exists($f, 'emisor')) $f->emisor = $business; return $f; }
        return new static($business);
    }
}
