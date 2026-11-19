<?php

namespace App\Services\Compras;

use App\Models\Alerta;
use App\Models\Business;
use App\Models\CompraBorrador;
use App\Models\Comprobante;
use App\Models\ComprobanteAdjunto;
use App\Models\Contact;
use App\Models\FacturaEntrante;
use App\Models\SistemaConfig;
use App\Models\User;
use App\Services\Correo\ImapCliente;
use App\Services\Correo\Mime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Fase 120.3: la casilla de facturas. Cada empresa tiene una dirección propia (facturas+código@…); lo que llega ahí —o lo que se
// sube de a varias— se lee con la misma IA de «leer factura», y queda como factura a medio cargar para revisar y registrar.
// Nada se registra solo: la casilla ahorra el tipeo, la decisión sigue siendo de la persona.
class BandejaFacturasService
{
    public const MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    public const PESO_MAX = 12 * 1024 * 1024;
    private const TIPOS = ['FA', 'FB', 'FC', 'FE', 'NCA', 'NCB', 'NCC', 'NDA', 'NDB', 'NDC'];

    public function __construct(private CompraOCRService $ocr, private LecturaCompraService $lectura) {}

    // ---- La casilla del sistema (Panel → Sistema) ----
    public static function config(): array
    {
        return ['host' => SistemaConfig::get('casilla_host'), 'puerto' => (int) (SistemaConfig::get('casilla_puerto') ?: 993), 'usuario' => SistemaConfig::get('casilla_usuario'), 'clave' => SistemaConfig::get('casilla_clave'), 'direccion' => SistemaConfig::get('casilla_direccion') ?: SistemaConfig::get('casilla_usuario')];
    }

    public static function configurada(): bool
    {
        $c = self::config();
        return ! empty($c['host']) && ! empty($c['usuario']) && ! empty($c['clave']);
    }

    // El código de la empresa en la dirección; se crea la primera vez que hace falta.
    public static function token(Business $b): string
    {
        if ($b->casilla_token) return $b->casilla_token;
        do { $t = strtolower(Str::random(10)); } while (Business::where('casilla_token', $t)->exists());
        $b->forceFill(['casilla_token' => $t])->save();
        return $t;
    }

    // facturas+abc123@bigsysweb.com: adonde la empresa (o sus proveedores) mandan las facturas.
    public static function direccion(Business $b): ?string
    {
        $base = self::config()['direccion'];
        if (! self::configurada() || ! $base || ! str_contains($base, '@')) return null;
        [$u, $d] = explode('@', $base, 2);
        return $u . '+' . self::token($b) . '@' . $d;
    }

    private function abrir(): ImapCliente
    {
        $c = self::config();
        $imap = ImapCliente::abrir($c['host'], $c['puerto']);
        $imap->entrar($c['usuario'], $c['clave']);
        return $imap;
    }

    // Para el botón «Probar la casilla»: entra y cuenta lo que hay sin leer.
    public function probar(): array
    {
        if (! self::configurada()) return ['ok' => false, 'texto' => 'Falta cargar el servidor, el usuario y la clave de la casilla.'];
        try { $imap = $this->abrir(); $n = count($imap->sinLeer(200)); $imap->salir(); }
        catch (\Throwable $e) { return ['ok' => false, 'texto' => $e->getMessage()]; }
        return ['ok' => true, 'texto' => "La casilla contesta bien. Mensajes sin leer: {$n}."];
    }

    // Lo que corre cada cinco minutos: baja lo nuevo, lo reparte por empresa y lo deja leído.
    public function revisar(?ImapCliente $imap = null, int $max = 15): array
    {
        $r = ['correos' => 0, 'facturas' => 0, 'sin_empresa' => 0, 'error' => null];
        if (! $imap && ! self::configurada()) return $r;
        try {
            $imap ??= $this->abrir();
            foreach ($imap->sinLeer($max) as $uid) {
                $crudo = $imap->mensaje($uid);
                if ($crudo === null) continue;
                try {
                    foreach ($this->procesarCorreo(Mime::leer($crudo)) as $e) {
                        if ($e->estado === 'sin_empresa') $r['sin_empresa']++;
                        if ($e->estado === 'lista') $r['facturas']++;
                    }
                } catch (\Throwable $e) { report($e); } // un mail roto no frena al resto; igual se marca leído para no reintentarlo siempre
                $imap->marcarLeido($uid);
                $r['correos']++;
            }
            $imap->salir();
        } catch (\Throwable $e) { report($e); $r['error'] = $e->getMessage(); }
        return $r;
    }

    // Un correo ya leído (Mime::leer) → una fila por cada factura adjunta.
    public function procesarCorreo(array $m): array
    {
        $de = $m['de'] ?? null;
        if (! empty($m['automatico']) || ($de && preg_match('/^(mailer-daemon|postmaster|no-?reply)@/i', $de))) return []; // rebotes y respuestas automáticas
        if (! empty($m['message_id']) && FacturaEntrante::withoutGlobalScopes()->where('message_id', $m['message_id'])->exists()) return []; // ya lo procesamos

        // Sólo PDF y fotos; las imágenes chicas en línea son firmas y logos, no facturas.
        $adj = array_values(array_filter($m['adjuntos'] ?? [], fn($a) => in_array($a['mime'], self::MIMES, true) && strlen($a['contenido']) <= self::PESO_MAX
            && ! (str_starts_with($a['mime'], 'image/') && (strlen($a['contenido']) < 30000 || (! empty($a['en_linea']) && strlen($a['contenido']) < 120000)))));
        $adj = array_slice($adj, 0, 10);

        $base = ['origen' => 'mail', 'remitente' => $de ? mb_substr($de, 0, 190) : null, 'asunto' => mb_substr((string) ($m['asunto'] ?? ''), 0, 200) ?: null, 'message_id' => isset($m['message_id']) ? mb_substr($m['message_id'], 0, 250) : null];
        $b = $this->empresaDe($m);
        if (! $b) { // sin eventos: que no se le pegue la empresa de quien esté logueado
            $e = (new FacturaEntrante)->forceFill($base + ['estado' => 'sin_empresa', 'detalle' => 'No se reconoció la empresa: ni el código de la dirección ni el remitente coinciden con nadie.', 'archivo' => isset($adj[0]) ? mb_substr($adj[0]['nombre'], 0, 200) : null]);
            $e->saveQuietly();
            return [$e];
        }
        if (! $adj) return [FacturaEntrante::create($base + ['business_id' => $b->id, 'estado' => 'sin_adjunto', 'detalle' => 'El correo no traía un PDF ni una foto de factura.'])];

        return array_map(fn($a) => $this->recibir($b, $a['contenido'], $a['mime'], $a['nombre'], $base), $adj);
    }

    // De quién es el correo: 1) el código en la dirección (facturas+código@) o en el asunto (#código); 2) lo mandó un usuario;
    // 3) lo mandó un proveedor que está cargado con ese mail en una sola empresa.
    public function empresaDe(array $m): ?Business
    {
        $codigos = [];
        foreach ($m['para'] ?? [] as $p) if (preg_match('/\+([a-z0-9]{6,16})@/i', $p, $x)) $codigos[] = strtolower($x[1]);
        if (preg_match_all('/#([a-z0-9]{6,16})\b/i', (string) ($m['asunto'] ?? ''), $x)) foreach ($x[1] as $c) $codigos[] = strtolower($c);
        if ($codigos && ($b = Business::whereIn('casilla_token', $codigos)->first())) return $b;

        $de = strtolower((string) ($m['de'] ?? ''));
        if ($de === '') return null;
        $ids = User::withoutGlobalScopes()->whereRaw('lower(email) = ?', [$de])->where('status', 'active')->whereNotNull('business_id')->pluck('business_id')->unique();
        if ($ids->count() === 1) return Business::find($ids->first());
        $ids = Contact::withoutGlobalScopes()->whereIn('type', ['supplier', 'both'])->whereRaw('lower(email) = ?', [$de])->pluck('business_id')->unique();
        return $ids->count() === 1 ? Business::find($ids->first()) : null;
    }

    // Una factura (PDF o foto) → leída y a medio cargar. $meta: origen, remitente, asunto, message_id.
    public function recibir(Business $b, string $contenido, string $mime, string $nombre, array $meta = [], ?User $por = null): FacturaEntrante
    {
        $hash = sha1($contenido);
        $base = ['business_id' => $b->id, 'origen' => $meta['origen'] ?? 'subida', 'remitente' => $meta['remitente'] ?? $por?->email, 'asunto' => $meta['asunto'] ?? null, 'message_id' => $meta['message_id'] ?? null, 'archivo' => mb_substr($nombre, 0, 200), 'mime' => $mime, 'hash' => $hash];

        $antes = FacturaEntrante::withoutGlobalScopes()->where('business_id', $b->id)->where('hash', $hash)->whereIn('estado', ['lista', 'cargada'])->first();
        if ($antes) return FacturaEntrante::create($base + ['estado' => 'duplicada', 'detalle' => 'Es el mismo archivo que llegó el ' . $antes->created_at->format('d/m/Y') . '.', 'proveedor' => $antes->proveedor, 'numero' => $antes->numero, 'total' => $antes->total]);

        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? 'bin';
        $ruta = "adjuntos/{$b->id}/" . Str::random(32) . '.' . $ext;
        Storage::disk('local')->put($ruta, $contenido);
        $base['ruta'] = $ruta;

        // Se lee «como» alguien de la empresa (el que la subió o el dueño), así el catálogo, los proveedores y la clave de IA son los suyos.
        $u = $por ?: ($b->owner ?: User::withoutGlobalScopes()->where('business_id', $b->id)->where('status', 'active')->orderBy('id')->first());
        if (! $u) return FacturaEntrante::create($base + ['estado' => 'sin_leer', 'detalle' => 'La empresa no tiene usuarios activos.']);
        $prev = Auth::user(); $cambio = ! $prev || $prev->id !== $u->id;
        if ($cambio) Auth::setUser($u);
        try {
            // La lectura corre con la IA de la empresa, igual que cuando lee una factura desde la pantalla.
            if (! \App\Support\Ia::tiene($b)) return FacturaEntrante::create($base + ['estado' => 'sin_leer', 'detalle' => 'La empresa no tiene la IA conectada (Configuración → Conexiones IA). Quedó el archivo para cargarla a mano.']);
            \App\Support\Ia::aplicar($b);
            $res = $this->ocr->leer(base64_encode($contenido), $mime);
            if (! $res['ok']) return FacturaEntrante::create($base + ['estado' => 'sin_leer', 'detalle' => mb_substr($res['aviso'] ?? 'No se pudo leer.', 0, 300)]);

            $prov = $res['proveedor_id'] ? Contact::find($res['proveedor_id']) : null;
            $d = $this->lectura->entender($res['datos'], $prov, null);
            $provId = $d['proveedor_id'] ?? $res['proveedor_id'];
            $prov = $provId ? Contact::find($provId) : null;
            $tipo = in_array($d['tipo'] ?? null, self::TIPOS, true) ? $d['tipo'] : 'FA';
            $numero = isset($d['numero']) ? mb_substr((string) $d['numero'], 0, 20) : null;
            $nombreProv = $prov?->name ?? ($d['proveedor']['nombre'] ?? null);
            $total = isset($d['total']) && is_numeric($d['total']) ? (float) $d['total'] : null;
            $resumen = ['proveedor' => $nombreProv ? mb_substr($nombreProv, 0, 120) : null, 'numero' => $numero, 'total' => $total];

            // ¿Ya está cargada, o ya está esperando a medio cargar?
            if ($prov && $numero) {
                if (Comprobante::compras()->where('contact_id', $prov->id)->where('tipo', $tipo)->where('numero_proveedor', $numero)->where('estado', '!=', 'anulado')->exists())
                    return FacturaEntrante::create($base + $resumen + ['estado' => 'duplicada', 'detalle' => "Esa factura ({$tipo} {$numero} de {$prov->name}) ya está cargada en Compras."]);
                if (CompraBorrador::where('contact_id', $prov->id)->where('numero', $numero)->exists())
                    return FacturaEntrante::create($base + $resumen + ['estado' => 'duplicada', 'detalle' => "Esa factura ({$tipo} {$numero} de {$prov->name}) ya está a medio cargar."]);
            }

            $adj = ComprobanteAdjunto::create(['business_id' => $b->id, 'user_id' => $u->id, 'tipo' => $mime === 'application/pdf' ? 'pdf' : 'imagen', 'archivo' => $ruta, 'items_detectados' => $d, 'confianza' => $d['confianza'] ?? null]);
            // Los mismos datos que arma la pantalla cuando lee una factura: al «Retomar» aparece tal cual.
            $datos = [
                'contact_id' => $prov?->id, 'tipo' => $tipo, 'numero_proveedor' => $numero, 'fecha' => $d['fecha'] ?? today()->toDateString(), 'fecha_vto' => $d['fecha_vto'] ?? null, 'cae_proveedor' => $d['cae'] ?? null,
                'origen_carga' => 'ocr', 'adjunto_id' => $adj->id,
                'items' => array_values(array_map(fn($i) => ['product_id' => $i['product_id'] ?? null, 'descripcion' => $i['descripcion'] ?? '', 'codigo' => $i['codigo'] ?? null, 'cantidad' => (float) ($i['cantidad'] ?? 1) ?: 1, 'unidad' => null, 'precio_unit' => (float) ($i['precio_unit'] ?? 0), 'descuento' => (float) ($i['descuento'] ?? 0), 'alicuota_iva' => (float) ($i['alicuota_iva'] ?? 21), 'actualiza_costo' => $i['actualiza_costo'] ?? empty($i['sin_cargo']), 'bulto' => $i['bulto'] ?? null, 'por' => $i['por'] ?? null, 'sin_cargo' => ! empty($i['sin_cargo'])], $d['items'] ?? [])),
                'impuestos' => array_values(array_map(fn($p) => ['tipo' => str_starts_with((string) ($p['tipo'] ?? 'otros'), 'iibb') ? 'iibb' : (in_array($p['tipo'] ?? '', ['iva', 'ganancias'], true) ? $p['tipo'] : 'otros'), 'jurisdiccion' => '', 'monto' => (float) $p['monto']], array_filter($d['percepciones'] ?? [], fn($p) => (float) ($p['monto'] ?? 0) > 0))),
                'ocr' => ['totalLeido' => $total, 'avisos' => $d['avisos'] ?? []],
                'proveedor_nombre' => $nombreProv,
                'proveedor_cuit' => $prov ? null : ($d['proveedor']['cuit'] ?? null),
                'adjuntos' => [$adj->id],
            ];
            $bor = $this->lectura->guardarBorrador($u->id, $b->id, $datos);
            $e = FacturaEntrante::create($base + $resumen + ['estado' => 'lista', 'borrador_id' => $bor->id, 'detalle' => $prov ? null : ($nombreProv ? "El proveedor «{$nombreProv}» todavía no está cargado." : 'No se reconoció el proveedor.')]);

            Alerta::create(['business_id' => $b->id, 'modulo' => 'proveedores', 'tipo' => 'factura_casilla', 'severidad' => 'info', 'modelo' => 'FacturaEntrante', 'modelo_id' => $e->id,
                'titulo' => 'Llegó una factura' . ($nombreProv ? " de {$nombreProv}" : '') . (($meta['origen'] ?? 'subida') === 'mail' ? ' por mail' : ''),
                'detalle' => trim(($numero ? "{$tipo} {$numero}" : '') . ($total ? ' por $ ' . number_format($total, 2, ',', '.') : '') . '. Ya está leída: revisala y registrala.'), 'url' => "/proveedores/compras/nueva?borrador={$bor->id}"]);
            return $e;
        } catch (\Throwable $e) {
            report($e);
            return FacturaEntrante::create($base + ['estado' => 'sin_leer', 'detalle' => 'Falló la lectura: ' . mb_substr($e->getMessage(), 0, 200)]);
        } finally {
            if ($cambio) { if ($prev) Auth::setUser($prev); elseif (method_exists(Auth::guard(), 'forgetUser')) Auth::guard()->forgetUser(); }
        }
    }

    // Cuando la compra se registra desde el borrador, la entrada queda como cargada.
    public static function cargada(int $borradorId, ?int $compraId): void
    {
        FacturaEntrante::where('borrador_id', $borradorId)->where('estado', 'lista')->update(['estado' => 'cargada', 'compra_id' => $compraId]);
    }

    // Si alguien descarta el borrador desde «a medio cargar», la entrada también queda descartada.
    public static function descartadaPorBorrador(int $borradorId): void
    {
        FacturaEntrante::where('borrador_id', $borradorId)->where('estado', 'lista')->update(['estado' => 'descartada']);
    }

    public function descartar(FacturaEntrante $e): void
    {
        if ($e->borrador_id && $e->estado === 'lista') CompraBorrador::whereKey($e->borrador_id)->delete();
        $e->update(['estado' => 'descartada']);
        Alerta::where('tipo', 'factura_casilla')->where('modelo_id', $e->id)->whereNull('resuelta_en')->update(['resuelta_en' => now()]);
    }

    // Lo que muestra Compras: la dirección de la empresa y lo último que llegó.
    public function resumen(Business $b, int $n = 12): array
    {
        $filas = FacturaEntrante::where('business_id', $b->id)->where('estado', '!=', 'descartada')->where('created_at', '>=', now()->subDays(30))->orderByDesc('id')->limit($n)->get();
        return [
            'direccion' => self::direccion($b),
            'por_revisar' => FacturaEntrante::where('business_id', $b->id)->where('estado', 'lista')->count(),
            'lista' => $filas->map(fn($e) => ['id' => $e->id, 'origen' => $e->origen, 'remitente' => $e->remitente, 'archivo' => $e->archivo, 'estado' => $e->estado, 'estado_txt' => FacturaEntrante::ESTADOS[$e->estado] ?? $e->estado,
                'detalle' => $e->detalle, 'proveedor' => $e->proveedor, 'numero' => $e->numero, 'total' => $e->total, 'borrador_id' => $e->estado === 'lista' ? $e->borrador_id : null, 'compra_id' => $e->compra_id,
                'tiene_archivo' => (bool) $e->ruta, 'cuando' => $e->created_at->diffForHumans()])->values()->all(),
        ];
    }
}
