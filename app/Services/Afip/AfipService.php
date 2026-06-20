<?php
namespace App\Services\Afip;

use Afip;
use App\Models\Business;
use Illuminate\Support\Facades\Storage;

class AfipService
{
    private Afip $afip;
    private Business $business;

    public function __construct(Business $business)
    {
        $this->business = $business;
        $this->afip = new Afip([
            'CUIT'       => $business->cuit,
            'cert'       => $this->resolvePath($business->afip_cert_path),
            'key'        => $this->resolvePath($business->afip_key_path),
            'production' => $business->afip_produccion,
        ]);
    }

    public function getLastVoucher(int $puntoVenta, int $tipoComprobante): int
    {
        return $this->afip->ElectronicBilling->GetLastVoucher($puntoVenta, $tipoComprobante);
    }

    public function createVoucher(array $data): array
    {
        return $this->afip->ElectronicBilling->CreateVoucher($data);
    }

    public function getVoucherInfo(int $numero, int $puntoVenta, int $tipoComprobante): array
    {
        return $this->afip->ElectronicBilling->GetVoucherInfo($numero, $puntoVenta, $tipoComprobante);
    }

    public function getServerStatus(): array
    {
        return $this->afip->ElectronicBilling->GetServerStatus();
    }

    private function resolvePath(?string $path): string
    {
        if (! $path) return '';
        return Storage::path($path);
    }

    public static function forBusiness(Business $business): static
    {
        return new static($business);
    }
}
