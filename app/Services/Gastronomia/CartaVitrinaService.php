<?php

namespace App\Services\Gastronomia;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Fase 127: la carta vitrina de Big Restó. La carta del QR deja de ser una lista y pasa a mostrar, arriba, la historia del
// local con su foto y el horario, y después un plato por pantalla con la foto grande, ingredientes, alérgenos, tamaños y
// precio. Las fotos se suben desde el sistema (antes sólo se podía pegar un enlace) y se sirven desde el ERP.
class CartaVitrinaService
{
    // Los alérgenos que el Código Alimentario Argentino (art. 235 séptimo) obliga a declarar en los alimentos envasados.
    // En la carta es información para el cliente: la carga el local, el sistema no la deduce.
    public const ALERGENOS = [
        'gluten' => 'Gluten (TACC)', 'leche' => 'Leche', 'huevo' => 'Huevo', 'mani' => 'Maní', 'frutos_secos' => 'Frutos secos',
        'soja' => 'Soja', 'pescado' => 'Pescado', 'crustaceos' => 'Crustáceos', 'sulfitos' => 'Sulfitos',
    ];
    private const ALERGENOS_EN = ['gluten' => 'Gluten', 'leche' => 'Milk', 'huevo' => 'Egg', 'mani' => 'Peanuts', 'frutos_secos' => 'Tree nuts', 'soja' => 'Soy', 'pescado' => 'Fish', 'crustaceos' => 'Shellfish', 'sulfitos' => 'Sulphites'];
    private const ALERGENOS_PT = ['gluten' => 'Glúten', 'leche' => 'Leite', 'huevo' => 'Ovo', 'mani' => 'Amendoim', 'frutos_secos' => 'Castanhas', 'soja' => 'Soja', 'pescado' => 'Peixe', 'crustaceos' => 'Crustáceos', 'sulfitos' => 'Sulfitos'];
    public static function alergeno(string $k, string $lang = 'es'): string { return match ($lang) { 'en' => self::ALERGENOS_EN[$k] ?? $k, 'pt' => self::ALERGENOS_PT[$k] ?? $k, default => self::ALERGENOS[$k] ?? $k }; }
    public const LADO = 1200;      // las fotos se achican a 1200 px del lado más largo
    public const MB = 8;           // y se aceptan hasta 8 MB (las de un celular)

    public function subirFoto(Product $p, UploadedFile $f): string
    {
        $ruta = $this->guardar($f, "carta/{$p->business_id}", "plato-{$p->id}");
        if ($p->foto_carta && $p->foto_carta !== $ruta) Storage::disk('local')->delete($p->foto_carta);
        $p->forceFill(['foto_carta' => $ruta, 'imagen' => self::urlPlato($p->id)])->save();
        return $ruta;
    }

    public function quitarFoto(Product $p): void
    {
        if ($p->foto_carta) Storage::disk('local')->delete($p->foto_carta);
        $p->forceFill(['foto_carta' => null, 'foto_gira' => false] + ($p->imagen && str_contains($p->imagen, '/fotos/plato/') ? ['imagen' => null] : []))->save();
    }

    public function subirPortada(Business $b, UploadedFile $f): void
    {
        $t = $b->tienda ?? [];
        $ruta = $this->guardar($f, "carta/{$b->id}", 'portada');
        if (! empty($t['menu_portada']) && $t['menu_portada'] !== $ruta) Storage::disk('local')->delete($t['menu_portada']);
        $b->forceFill(['tienda' => array_merge($t, ['menu_portada' => $ruta])])->save();
    }

    public function quitarPortada(Business $b): void
    {
        $t = $b->tienda ?? [];
        if (! empty($t['menu_portada'])) Storage::disk('local')->delete($t['menu_portada']);
        $b->forceFill(['tienda' => array_merge($t, ['menu_portada' => null])])->save();
    }

    // La dirección pública de la foto; el ?v= cambia con cada foto nueva para que el celular no muestre la vieja.
    public static function urlPlato(int $id): string { return url("/fotos/plato/{$id}") . '?v=' . now()->timestamp; }
    public static function urlPortada(Business $b): ?string { return ! empty($b->tienda['menu_portada']) ? url("/fotos/portada/{$b->id}") . '?v=' . substr(md5((string) $b->tienda['menu_portada']), 0, 8) : null; }

    // Achica la foto (GD) y la guarda en el disco local: JPG, o PNG si trae transparencia (un plato recortado).
    private function guardar(UploadedFile $f, string $dir, string $nombre): string
    {
        $tipo = strtolower((string) $f->getMimeType());
        if (! in_array($tipo, ['image/jpeg', 'image/png', 'image/webp'], true)) throw ValidationException::withMessages(['foto' => 'Subí la foto en JPG, PNG o WEBP.']);
        if ($f->getSize() > self::MB * 1024 * 1024) throw ValidationException::withMessages(['foto' => 'La foto puede pesar hasta ' . self::MB . ' MB.']);
        $img = @imagecreatefromstring((string) file_get_contents($f->getRealPath()));
        if (! $img) throw ValidationException::withMessages(['foto' => 'No se pudo leer la foto. Probá con otra.']);
        if ($tipo === 'image/jpeg' && function_exists('exif_read_data')) {
            // Las fotos del celular vienen giradas: se endereza según la orientación que anotó la cámara.
            $o = (int) (@exif_read_data($f->getRealPath())['Orientation'] ?? 1);
            $img = match ($o) { 3 => imagerotate($img, 180, 0), 6 => imagerotate($img, -90, 0), 8 => imagerotate($img, 90, 0), default => $img };
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        $k = min(1, self::LADO / max($w, $h));
        $alfa = $tipo !== 'image/jpeg';
        if ($k < 1) {
            $n = imagecreatetruecolor((int) round($w * $k), (int) round($h * $k));
            if ($alfa) { imagealphablending($n, false); imagesavealpha($n, true); }
            imagecopyresampled($n, $img, 0, 0, 0, 0, imagesx($n), imagesy($n), $w, $h); $img = $n;
        }
        $png = $alfa && $this->tieneTransparencia($img);
        Storage::disk('local')->makeDirectory($dir);
        $ruta = "{$dir}/{$nombre}-" . now()->format('YmdHis') . ($png ? '.png' : '.jpg');
        $abs = Storage::disk('local')->path($ruta);
        if ($png) { imagesavealpha($img, true); imagepng($img, $abs, 7); }
        else {
            if ($alfa) { $fondo = imagecreatetruecolor(imagesx($img), imagesy($img)); imagefill($fondo, 0, 0, imagecolorallocate($fondo, 255, 255, 255)); imagecopy($fondo, $img, 0, 0, 0, 0, imagesx($img), imagesy($img)); $img = $fondo; }
            imagejpeg($img, $abs, 82);
        }
        return $ruta;
    }

    private function tieneTransparencia(\GdImage $img): bool
    {
        $w = imagesx($img); $h = imagesy($img); $paso = max(1, (int) floor(min($w, $h) / 24));
        for ($x = 0; $x < $w; $x += $paso) for ($y = 0; $y < $h; $y += $paso) if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) return true;
        return false;
    }

    // Lo que se guarda del local para la vitrina (en la misma configuración que la tienda y el QR).
    public function guardarConfig(Business $b, array $d): void
    {
        $t = $b->tienda ?? [];
        foreach (['menu_estilo', 'menu_historia_titulo', 'menu_historia', 'menu_horario'] as $k) if (array_key_exists($k, $d)) $t[$k] = is_string($d[$k]) ? (trim($d[$k]) ?: null) : $d[$k];
        $b->forceFill(['tienda' => $t])->save();
    }
}
