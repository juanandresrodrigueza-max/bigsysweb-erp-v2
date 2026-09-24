<?php

namespace App\Services\Afip;

use App\Models\Business;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

// Certificado y clave privada de AFIP cifrados en disco (extensión .enc). Para usarlos se descifran a un archivo temporal solo legible por el proceso.
class CertificadoCifrado
{
    public static function guardar(Business $b, string $nombre, string $contenido, ?int $sucursalId = null): string
    {
        $path = "afip/{$b->id}/" . ($sucursalId ? "suc-{$sucursalId}/" : '') . "{$nombre}.enc";
        Storage::disk('local')->put($path, Crypt::encryptString($contenido));
        // Si quedaba una versión sin cifrar, se borra.
        Storage::disk('local')->delete("afip/{$b->id}/{$nombre}");
        return $path;
    }

    // Devuelve una ruta legible por la librería de AFIP: la original si no está cifrada, o una copia temporal descifrada (0600).
    public static function rutaLegible(string $path): string
    {
        if (! str_ends_with($path, '.enc')) return Storage::path($path);
        $tmp = storage_path('app/afip/tmp/' . md5($path . config('app.key')) . '.pem');
        if (! is_dir(dirname($tmp))) mkdir(dirname($tmp), 0700, true);
        $contenido = Crypt::decryptString(Storage::disk('local')->get($path));
        if (! is_file($tmp) || file_get_contents($tmp) !== $contenido) { file_put_contents($tmp, $contenido); chmod($tmp, 0600); }
        return $tmp;
    }

    // Cifra los certificados que hayan quedado en claro de instalaciones anteriores. Devuelve cuántas empresas se actualizaron.
    public static function cifrarExistentes(): int
    {
        $n = 0;
        foreach (Business::withoutGlobalScopes()->whereNotNull('afip_cert_path')->get() as $b) {
            $upd = [];
            foreach (['afip_cert_path' => 'cert.crt', 'afip_key_path' => 'private.key'] as $campo => $nombre) {
                $p = $b->$campo;
                if ($p && ! str_ends_with($p, '.enc') && Storage::disk('local')->exists($p)) $upd[$campo] = self::guardar($b, $nombre, Storage::disk('local')->get($p));
            }
            if ($upd) { $b->forceFill($upd)->save(); $n++; }
        }
        return $n;
    }
}
