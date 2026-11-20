<?php

namespace App\Services\Afip;

use App\Models\ArcaTicket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

// Fase 121: conexión DIRECTA con ARCA (WSAA + WSFEv1), igual que la del CRM (server/lib/afipWs.ts), pero en PHP.
//
// Se habla con ARCA desde este servidor, sin librerías ni servicios intermediarios: se firma el pedido de acceso con el
// certificado y se hacen las llamadas SOAP a mano. Lo mismo sirve para los dos modelos:
//  · BigSys factura por la empresa: el certificado es el de BigSys y el CUIT que viaja en Auth.Cuit es el de la empresa,
//    que le delegó el servicio a BigSys desde su Administrador de Relaciones de Clave Fiscal.
//  · La empresa tiene su propio certificado: certificado y CUIT son de la empresa.
//
// Reemplaza a la librería vieja para WSFE porque esa trae un WSDL anterior a la RG 5616: descartaba en silencio la
// condición frente al IVA del receptor y el período asociado de las notas; y guardaba el ticket de acceso por CUIT
// (con un certificado para muchas empresas, ARCA rechaza el segundo ticket).
class ArcaDirecto
{
    public const URLS = [
        'wsaa' => ['prod' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms', 'homo' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms'],
        'wsfe' => ['prod' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx', 'homo' => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx'],
    ];
    private const NS = 'http://ar.gov.afip.dif.FEV1/';
    private const MARGEN_SEGUNDOS = 600; // pedir un ticket nuevo justo al filo es quedarse sin ticket en medio de una emisión

    // Orden de los campos del detalle, según el WSDL vigente de WSFEv1 (servicio .NET con secuencia fija: un campo fuera de
    // lugar rebota con un error de esquema que no dice cuál es). Es el mismo orden que usa el CRM en producción.
    private const ORDEN_DETALLE = ['Concepto', 'DocTipo', 'DocNro', 'CbteDesde', 'CbteHasta', 'CbteFch', 'ImpTotal', 'ImpTotConc', 'ImpNeto', 'ImpOpEx', 'ImpTrib', 'ImpIVA',
        'FchServDesde', 'FchServHasta', 'FchVtoPago', 'MonId', 'MonCotiz', 'CanMisMonExt', 'CondicionIVAReceptorId', 'CbtesAsoc', 'Tributos', 'Iva', 'Opcionales', 'Compradores', 'PeriodoAsoc', 'Actividades'];
    private const LISTAS = [ // contenedor => [elemento, orden de sus campos]
        'CbtesAsoc' => ['CbteAsoc', ['Tipo', 'PtoVta', 'Nro', 'Cuit', 'CbteFch']],
        'Tributos' => ['Tributo', ['Id', 'Desc', 'BaseImp', 'Alic', 'Importe']],
        'Iva' => ['AlicIva', ['Id', 'BaseImp', 'Importe']],
        'Opcionales' => ['Opcional', ['Id', 'Valor']],
        'Compradores' => ['Comprador', ['DocTipo', 'DocNro', 'Porcentaje']],
        'Actividades' => ['Actividad', ['Id']],
    ];
    private const IMPORTES = ['ImpTotal', 'ImpTotConc', 'ImpNeto', 'ImpOpEx', 'ImpTrib', 'ImpIVA', 'BaseImp', 'Importe'];

    /** @param bool $plataforma true = certificado de BigSys (los mensajes dicen de quién es el problema) */
    public function __construct(private string $cert, private string $key, private bool $produccion, private bool $plataforma = false) {}

    public function produccion(): bool { return $this->produccion; }

    public function huella(): string { return hash('sha256', preg_replace('/\s+/', '', $this->cert)); }

    // ─── XML: lo mínimo indispensable (las respuestas de WSFEv1 son planas y de estructura fija) ─────────────────────────
    private static function tag(string $xml, string $nombre): ?string
    {
        return preg_match('/<(?:\w+:)?' . $nombre . '(?:\s[^>]*)?>([\s\S]*?)<\/(?:\w+:)?' . $nombre . '>/', $xml, $m) ? $m[1] : null;
    }

    private static function tags(string $xml, string $nombre): array
    {
        preg_match_all('/<(?:\w+:)?' . $nombre . '(?:\s[^>]*)?>([\s\S]*?)<\/(?:\w+:)?' . $nombre . '>/', $xml, $m);
        return $m[1];
    }

    private static function esc($v): string { return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

    // ─── WSAA: el ticket de acceso ───────────────────────────────────────────────────────────────────────────────────────
    // Dura 12 horas y ARCA rechaza pedir otro mientras el anterior siga vigente, así que se guarda en la base y se pide de a
    // uno por vez (un candado evita que dos emisiones simultáneas pidan dos tickets y la segunda rebote).
    public function ticket(string $servicio = 'wsfe'): array
    {
        if ($t = $this->ticketGuardado($servicio)) return $t;
        $candado = Cache::lock('arca:login:' . $this->huella() . ":{$servicio}:" . ($this->produccion ? 'p' : 'h'), 60);
        try {
            $candado->block(45);
            if ($t = $this->ticketGuardado($servicio)) return $t; // lo pidió otro proceso mientras esperábamos
            return $this->login($servicio);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            throw new ArcaError('ARCA está tardando en entregar el ticket de acceso. Probá de nuevo en un minuto.');
        } finally {
            optional($candado)->release();
        }
    }

    private function ticketGuardado(string $servicio): ?array
    {
        $t = ArcaTicket::where('huella', $this->huella())->where('servicio', $servicio)->where('produccion', $this->produccion)->first();
        if (! $t || $t->expira->timestamp - self::MARGEN_SEGUNDOS < time()) return null;
        try { return ['token' => Crypt::decryptString($t->token), 'sign' => Crypt::decryptString($t->sign), 'expira' => $t->expira]; }
        catch (\Throwable $e) { return null; }
    }

    // El pedido de acceso firmado en CMS (PKCS#7, DER, sin separar), que es lo que pide WSAA. Certificado y clave van a un
    // directorio temporal propio y se borran SIEMPRE: nunca quedan en un archivo de larga vida.
    public function firmarPedido(string $servicio): string
    {
        $ahora = time();
        $iso = fn(int $s) => gmdate('Y-m-d\TH:i:s\Z', $s);
        // La ventana arranca 10 minutos antes por si el reloj del servidor difiere del de ARCA: un pedido «del futuro» lo rechaza sin explicar.
        $tra = '<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"><header><uniqueId>' . $ahora . '</uniqueId>'
            . '<generationTime>' . $iso($ahora - 600) . '</generationTime><expirationTime>' . $iso($ahora + 600) . '</expirationTime></header>'
            . '<service>' . self::esc($servicio) . '</service></loginTicketRequest>';
        $dir = sys_get_temp_dir() . '/arca-' . bin2hex(random_bytes(8));
        if (! @mkdir($dir, 0700)) throw new ArcaError('No se pudo preparar la firma del pedido de acceso a ARCA (directorio temporal).');
        try {
            file_put_contents("{$dir}/tra.xml", $tra);
            file_put_contents("{$dir}/cert.pem", $this->cert); chmod("{$dir}/cert.pem", 0600);
            file_put_contents("{$dir}/key.pem", $this->key); chmod("{$dir}/key.pem", 0600);
            $ok = @openssl_cms_sign("{$dir}/tra.xml", "{$dir}/tra.cms", 'file://' . "{$dir}/cert.pem", ['file://' . "{$dir}/key.pem", ''], [], OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
            if (! $ok) {
                $err = []; while ($e = openssl_error_string()) $err[] = $e;
                throw new ArcaError('No se pudo firmar el pedido de acceso a ARCA con el certificado cargado' . ($err ? ' (' . implode('; ', array_slice($err, 0, 2)) . ')' : '') . '. Revisá que el certificado y la clave privada sean pareja y estén completos.');
            }
            return base64_encode(file_get_contents("{$dir}/tra.cms"));
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }

    private function login(string $servicio): array
    {
        $cms = $this->firmarPedido($servicio);
        $url = self::URLS['wsaa'][$this->produccion ? 'prod' : 'homo'];
        $sobre = '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:wsaa="http://wsaa.view.sua.dvadac.desein.afip.gov">'
            . "<soapenv:Header/><soapenv:Body><wsaa:loginCms><wsaa:in0>{$cms}</wsaa:in0></wsaa:loginCms></soapenv:Body></soapenv:Envelope>";
        try {
            $r = Http::timeout(30)->withOptions(self::opcionesConexion($url))->withHeaders(['SOAPAction' => '""'])->withBody($sobre, 'text/xml; charset=utf-8')->post($url);
        } catch (\Throwable $e) {
            throw new ArcaError('No se pudo conectar con ARCA (WSAA): ' . $e->getMessage());
        }
        $xml = (string) $r->body();
        if ($fault = self::tag($xml, 'faultstring')) throw new ArcaError($this->traducirWsaa(html_entity_decode($fault), (string) self::tag($xml, 'faultcode')), self::tag($xml, 'faultcode'));
        // ARCA devuelve el XML del ticket ESCAPADO adentro del SOAP.
        $ticket = html_entity_decode((string) (self::tag($xml, 'loginCmsReturn') ?? $xml), ENT_QUOTES | ENT_XML1);
        $token = self::tag($ticket, 'token'); $sign = self::tag($ticket, 'sign'); $vence = self::tag($ticket, 'expirationTime');
        if (! $token || ! $sign) throw new ArcaError('ARCA no devolvió un ticket de acceso válido' . ($r->successful() ? '.' : " (HTTP {$r->status()})."));
        $expira = $vence ? \Carbon\Carbon::parse($vence) : now()->addHours(12);
        ArcaTicket::updateOrCreate(['huella' => $this->huella(), 'servicio' => $servicio, 'produccion' => $this->produccion], ['token' => Crypt::encryptString($token), 'sign' => Crypt::encryptString($sign), 'expira' => $expira]);
        return ['token' => $token, 'sign' => $sign, 'expira' => $expira];
    }

    // Los rechazos del WSAA que se ven de verdad, dichos en castellano y diciendo DE QUIÉN es el problema.
    private function traducirWsaa(string $fault, string $codigo = ''): string
    {
        $quien = $this->plataforma ? 'el certificado de BigSys' : 'tu certificado';
        if (preg_match('/no autorizado a acceder al servicio|not authorized/i', $fault)) return $this->plataforma
            ? 'El certificado de BigSys todavía no tiene asociado el servicio de Facturación Electrónica (wsfe) en ARCA. Es un trámite de BigSys, no de la empresa: en ARCA → Administrador de Relaciones, hay que darle «Facturación Electrónica» al computador fiscal (el alias del certificado).'
            : 'Tu certificado todavía no tiene asociado el servicio de Facturación Electrónica (wsfe): en ARCA → Administrador de Relaciones, asociá «Facturación Electrónica» al computador fiscal (el alias del certificado).';
        if (preg_match('/ya posee un TA valido|alreadyAuthenticated/i', $fault . ' ' . $codigo)) return 'ARCA dice que ya hay un ticket de acceso vigente para ' . $quien . ' y no entrega otro hasta que venza (dura 12 horas). '
            . ($this->plataforma ? 'Pasa cuando OTRO sistema usa el mismo certificado (por ejemplo, el CRM): el ERP necesita su propio certificado de BigSys (otro alias del mismo CUIT). Ver Panel → Sistema → Facturación electrónica.' : 'Se resuelve solo en unas horas; si se repite, otro sistema está usando el mismo certificado.');
        if (preg_match('/no emitido por AC de confianza|autoridad certificante|untrusted/i', $fault)) return 'ARCA no reconoce ' . $quien . ' en este ambiente: o no es un certificado emitido por ARCA, o es de prueba (homologación) y está puesto en producción, o al revés (cada ambiente sólo acepta los suyos). '
            . ($this->plataforma ? 'Se arregla en Panel → Sistema → Facturación electrónica, eligiendo el ambiente que corresponde al certificado.' : 'Elegí el ambiente que corresponde al certificado.');
        if (preg_match('/expirad|vencid|expired/i', $fault)) return ucfirst($quien) . ' está vencido: hay que renovarlo en ARCA y volver a cargarlo.';
        if (preg_match('/generationTime|expirationTime|hora|time/i', $fault)) return 'ARCA rechazó el pedido de acceso por la hora: el reloj del servidor tiene que estar en hora. (' . $fault . ')';
        return 'ARCA rechazó el acceso: ' . $fault;
    }

    // ─── WSFEv1 ──────────────────────────────────────────────────────────────────────────────────────────────────────────
    // `$cuit` es el CUIT que factura: el de la empresa (representada, cuando el certificado es de BigSys).
    private function wsfe(string $cuit, string $metodo, string $cuerpo = '', bool $auth = true, bool $reintento = false): string
    {
        try { return $this->llamarWsfe($cuit, $metodo, $cuerpo, $auth); }
        catch (ArcaError $e) {
            // ARCA no aceptó el ticket guardado (lo invalidó, o es de otro certificado): se descarta y se pide uno nuevo, una vez.
            if ($e->codigo !== 'token' || ! $auth || $reintento) throw $e;
            ArcaTicket::where('huella', $this->huella())->where('servicio', 'wsfe')->where('produccion', $this->produccion)->delete();
            return $this->wsfe($cuit, $metodo, $cuerpo, $auth, true);
        }
    }

    // El servidor de Facturación Electrónica de producción (servicios1.afip.gov.ar) todavía negocia TLS con una clave DH
    // chica que OpenSSL 3 rechaza por defecto («dh key too small»). Sólo para ese host se baja el nivel de seguridad de
    // OpenSSL a 1, como hace el CRM; el resto de las conexiones quedan con el nivel normal.
    public const HOSTS_DH_VIEJO = ['servicios1.afip.gov.ar'];

    public static function opcionesConexion(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return in_array($host, self::HOSTS_DH_VIEJO, true) ? ['curl' => [CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1']] : [];
    }

    private function llamarWsfe(string $cuit, string $metodo, string $cuerpo, bool $auth): string
    {
        $url = self::URLS['wsfe'][$this->produccion ? 'prod' : 'homo'];
        $a = '';
        if ($auth) { $t = $this->ticket('wsfe'); $a = '<ar:Auth><ar:Token>' . self::esc($t['token']) . '</ar:Token><ar:Sign>' . self::esc($t['sign']) . '</ar:Sign><ar:Cuit>' . self::esc(preg_replace('/\D/', '', $cuit)) . '</ar:Cuit></ar:Auth>'; }
        $sobre = '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ar="' . self::NS . '">'
            . "<soapenv:Header/><soapenv:Body><ar:{$metodo}>{$a}{$cuerpo}</ar:{$metodo}></soapenv:Body></soapenv:Envelope>";
        try {
            $r = Http::timeout(60)->withOptions(self::opcionesConexion($url))->withHeaders(['SOAPAction' => self::NS . $metodo])->withBody($sobre, 'text/xml; charset=utf-8')->post($url);
        } catch (\Throwable $e) {
            throw new ArcaError('No se pudo conectar con ARCA (WSFE): ' . $e->getMessage());
        }
        $xml = (string) $r->body();
        if ($fault = self::tag($xml, 'faultstring')) throw new ArcaError('ARCA rechazó la operación: ' . html_entity_decode($fault));
        if (! $r->successful() && ! str_contains($xml, $metodo . 'Response')) throw new ArcaError("No se pudo conectar con ARCA (WSFE): respondió HTTP {$r->status()}.");
        // Los errores vienen tanto en el método como adentro del resultado.
        $errs = self::tags((string) (self::tag($xml, 'Errors') ?? ''), 'Err');
        if ($errs) {
            $c = trim((string) self::tag($errs[0], 'Code')); $m = html_entity_decode(trim((string) self::tag($errs[0], 'Msg')));
            throw new ArcaError($this->traducirWsfe($c, $m), $c !== '' ? $c : null);
        }
        return $xml;
    }

    // Los códigos que se ven en la vida real, traducidos a «qué falta y quién lo arregla». El resto va con su código, para
    // poder buscarlo; el formato «(código) mensaje» es el que entiende AfipErrores (duplicados, fechas, importes…).
    private function traducirWsfe(string $codigo, string $msg): string
    {
        // El 600 de ARCA es «ValidacionDeToken»: puede ser que falte la delegación (no aparece el CUIT en la lista de relaciones)
        // o que el ticket no sirva. Son problemas distintos, con soluciones distintas.
        if ($codigo === '600' && ! preg_match('/lista de relaciones|no apareci[oó] cuit|relaci[oó]n/i', $msg)) throw new ArcaError('ARCA no aceptó el ticket de acceso (' . mb_substr($msg, 0, 160) . '). Reintentá: se pide uno nuevo.', 'token');
        if ($codigo === '600' || preg_match('/lista de relaciones|no apareci[oó] cuit/i', $msg)) return '(600) ' . ($this->plataforma
            ? 'Esta empresa todavía no autorizó a BigSys a facturar en su nombre ante ARCA, o lo autorizó con otro CUIT. Se hace desde ARCA → Administrador de Relaciones de Clave Fiscal → Nueva relación → Facturación Electrónica, con BigSys como representante. Ver Configuración → Puntos de venta y ARCA.'
            : 'El certificado no está autorizado a facturar con este CUIT (ARCA → Administrador de Relaciones). ' . $msg);
        if (preg_match('/punto de venta/i', $msg)) return "({$codigo}) {$msg} — revisá que el punto de venta esté dado de alta en ARCA para WEB SERVICES («RECE para aplicativo y web services», o «Factura Electrónica - Monotributo - Web Services» si es monotributista; el de «Comprobantes en línea» no sirve) y que sea el mismo número que en el sistema.";
        return $codigo !== '' ? "({$codigo}) {$msg}" : ($msg ?: 'ARCA rechazó la operación sin detallar el motivo.');
    }

    public function dummy(): array
    {
        $xml = $this->wsfe('', 'FEDummy', '', false);
        return ['AppServer' => trim((string) self::tag($xml, 'AppServer')), 'DbServer' => trim((string) self::tag($xml, 'DbServer')), 'AuthServer' => trim((string) self::tag($xml, 'AuthServer'))];
    }

    public function ultimoAutorizado(string $cuit, int $pv, int $tipo): int
    {
        $xml = $this->wsfe($cuit, 'FECompUltimoAutorizado', "<ar:PtoVta>{$pv}</ar:PtoVta><ar:CbteTipo>{$tipo}</ar:CbteTipo>");
        $n = self::tag($xml, 'CbteNro');
        if ($n === null) throw new ArcaError('ARCA no devolvió el último número autorizado.');
        return (int) $n;
    }

    // Los puntos de venta que ARCA tiene dados de alta para ese CUIT. Consulta pura: no emite ni reserva numeración.
    // 602 «sin resultados» no es una falla: ese CUIT no tiene ninguno dado de alta para web services todavía.
    public function puntosVenta(string $cuit): array
    {
        try { $xml = $this->wsfe($cuit, 'FEParamGetPtosVenta'); }
        catch (ArcaError $e) { if ($e->codigo === '602') return []; throw $e; }
        return collect(self::tags($xml, 'PtoVenta'))->map(function ($p) {
            $baja = trim((string) self::tag($p, 'FchBaja'));
            return ['numero' => (int) self::tag($p, 'Nro'), 'tipo' => trim((string) self::tag($p, 'EmisionTipo')), 'bloqueado' => (bool) preg_match('/^s(i)?$/i', trim((string) self::tag($p, 'Bloqueado'))), 'baja' => $baja !== '' && strcasecmp($baja, 'NULL') !== 0 ? $baja : null];
        })->filter(fn($p) => $p['numero'] > 0)->values()->all();
    }

    // Pide el CAE. `$d` es el detalle con los nombres de ARCA (como lo arma AfipEmisor::armarDatos): CantReg, PtoVta,
    // CbteTipo y los campos del detalle; Iva, Tributos, CbtesAsoc, Opcionales como listas; PeriodoAsoc como FchDesde/FchHasta.
    public function solicitarCae(string $cuit, array $d): array
    {
        $det = '';
        foreach (self::ORDEN_DETALLE as $campo) {
            if (! array_key_exists($campo, $d) || $d[$campo] === null || $d[$campo] === '' || $d[$campo] === []) continue;
            $v = $d[$campo];
            if (isset(self::LISTAS[$campo])) {
                [$el, $orden] = self::LISTAS[$campo];
                $det .= "<ar:{$campo}>";
                foreach ($v as $item) $det .= "<ar:{$el}>" . self::campos($item, $orden) . "</ar:{$el}>";
                $det .= "</ar:{$campo}>";
            } elseif ($campo === 'PeriodoAsoc') {
                $det .= '<ar:PeriodoAsoc>' . self::campos($v, ['FchDesde', 'FchHasta']) . '</ar:PeriodoAsoc>';
            } else {
                $det .= "<ar:{$campo}>" . self::valor($campo, $v) . "</ar:{$campo}>";
            }
        }
        $cuerpo = '<ar:FeCAEReq><ar:FeCabReq><ar:CantReg>' . (int) ($d['CantReg'] ?? 1) . '</ar:CantReg><ar:PtoVta>' . (int) $d['PtoVta'] . '</ar:PtoVta><ar:CbteTipo>' . (int) $d['CbteTipo'] . '</ar:CbteTipo></ar:FeCabReq>'
            . "<ar:FeDetReq><ar:FECAEDetRequest>{$det}</ar:FECAEDetRequest></ar:FeDetReq></ar:FeCAEReq>";
        $xml = $this->wsfe($cuit, 'FECAESolicitar', $cuerpo);

        $det = (string) (self::tag($xml, 'FECAEDetResponse') ?? $xml);
        $lista = self::tags((string) (self::tag($det, 'Observaciones') ?? ''), 'Obs');
        $obs = array_map(fn($o) => '(' . trim((string) self::tag($o, 'Code')) . ') ' . html_entity_decode(trim((string) self::tag($o, 'Msg'))), $lista);
        $resultado = trim((string) self::tag($det, 'Resultado')); $cae = trim((string) self::tag($det, 'CAE'));
        // «R» = rechazado. Las observaciones son el ÚNICO lugar donde ARCA dice por qué, así que van completas.
        if ($resultado === 'R' || $cae === '') throw new ArcaError('ARCA rechazó el comprobante.' . ($obs ? ' ' . implode(' | ', $obs) : ''), $lista ? trim((string) self::tag($lista[0], 'Code')) : null);
        return ['CAE' => $cae, 'CAEFchVto' => trim((string) self::tag($det, 'CAEFchVto')), 'Resultado' => $resultado, 'Observaciones' => $obs, 'CbteDesde' => (int) self::tag($det, 'CbteDesde')];
    }

    private static function valor(string $campo, $v): string
    {
        if (in_array($campo, self::IMPORTES, true)) return number_format((float) $v, 2, '.', ''); // con más decimales, la suma de ARCA no cierra
        if ($campo === 'MonCotiz' || $campo === 'Alic' || $campo === 'Porcentaje') return rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.') ?: '0';
        return self::esc(is_bool($v) ? ($v ? 'S' : 'N') : $v);
    }

    private static function campos(array $item, array $orden): string
    {
        $s = '';
        foreach ($orden as $k) if (array_key_exists($k, $item) && $item[$k] !== null && $item[$k] !== '') $s .= "<ar:{$k}>" . self::valor($k, $item[$k]) . "</ar:{$k}>";
        return $s;
    }

    // ¿Qué hay emitido en ese número? null si no existe (602). Devuelve los campos de ResultGet (CodAutorizacion, FchVto,
    // ImpTotal, CbteFch, Resultado…), igual que la librería vieja, para no tocar a quien la usa.
    public function consultar(string $cuit, int $pv, int $tipo, int $numero): ?array
    {
        try { $xml = $this->wsfe($cuit, 'FECompConsultar', "<ar:FeCompConsReq><ar:CbteTipo>{$tipo}</ar:CbteTipo><ar:CbteNro>{$numero}</ar:CbteNro><ar:PtoVta>{$pv}</ar:PtoVta></ar:FeCompConsReq>"); }
        catch (ArcaError $e) { if ($e->codigo === '602') return null; throw $e; }
        $rg = self::tag($xml, 'ResultGet');
        if ($rg === null) return null;
        $out = [];
        preg_match_all('/<(?:\w+:)?(\w+)>([^<]*)<\/(?:\w+:)?\1>/', $rg, $m, PREG_SET_ORDER);
        foreach ($m as [, $k, $v]) if (! array_key_exists($k, $out)) $out[$k] = html_entity_decode(trim($v));
        return ! empty($out['CodAutorizacion']) ? $out : null;
    }
}
