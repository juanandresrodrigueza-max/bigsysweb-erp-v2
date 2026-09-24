<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Services\Afip\AfipErrores;
use App\Services\Afip\QrArca;
use App\Services\Comprobantes\AfipEmisor;
use App\Services\Comprobantes\ComprobanteService;
use App\Support\Cuit;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Doble de ARCA para las pruebas: registra lo enviado y responde CAE.
class ArcaFalsa
{
    public array $enviados = [];
    public int $ultimo = 41;
    public ?\Closure $alCrear = null;
    public ?array $info = null;
    public ?object $emisor = null; // con qué CUIT/certificado se construyó el servicio (sucursal con CUIT propio o empresa)

    public function getLastVoucher(int $pv, int $tipo): int { return $this->ultimo; }
    public function createVoucher(array $data): array { $this->enviados[] = $data; if ($this->alCrear) return ($this->alCrear)($data); return ['CAE' => '71234567890123', 'CAEFchVto' => now()->addDays(10)->format('Ymd')]; }
    public function getVoucherInfo(int $numero, int $pv, int $tipo): ?array { return $this->info; }
    public function getServerStatus(): array { return ['AppServer' => 'OK', 'DbServer' => 'OK', 'AuthServer' => 'OK']; }
    public array $fexEnviados = []; public int $fexUltimo = 7; public int $fexUltimoId = 1000; public ?\Closure $fexAlAutorizar = null; public ?array $fexInfo = null;
    public function fexGetLastVoucher(int $pv, int $tipo): int { return $this->fexUltimo; }
    public function fexGetLastId(): int { return $this->fexUltimoId; }
    public function fexAuthorize(array $cmp): array { $this->fexEnviados[] = $cmp; if ($this->fexAlAutorizar) return ($this->fexAlAutorizar)($cmp); return ['Resultado' => 'A', 'CAE' => '79876543210987', 'CAEFchVto' => now()->addDays(10)->format('Ymd'), 'Cbte_nro' => $cmp['Cbte_nro'], 'Reproceso' => 'N', 'Motivos_Obs' => '', 'Id' => $cmp['Id']]; }
    public function fexGetVoucherInfo(int $pv, int $tipo, int $nro): ?array { return $this->fexInfo; }
    public function padron(string $cuit): ?array { return ['cuit' => Cuit::formatear($cuit), 'nombre' => 'ACME SRL', 'condicion_iva' => 'Responsable Inscripto', 'direccion' => 'Av. Colón 1234', 'localidad' => 'Córdoba', 'provincia' => 'Córdoba', 'codigo_postal' => '5000', 'estado' => 'ACTIVO']; }
}
