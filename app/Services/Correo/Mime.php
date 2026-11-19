<?php

namespace App\Services\Correo;

// Fase 120.3: lee un correo crudo (RFC 822 / MIME) y devuelve lo que nos importa: quién lo mandó, a quién, el asunto y los
// adjuntos ya decodificados. Chico a propósito: sin dependencias, sólo lo necesario para sacar facturas de un mail.
class Mime
{
    private const NIVEL_MAX = 6;

    public static function leer(string $crudo, int $nivel = 0): array
    {
        [$cab, $cuerpo] = self::partir($crudo);
        $h = self::cabeceras($cab);
        $out = [
            'de' => self::correos($h['from'] ?? '')[0] ?? null,
            'para' => array_values(array_unique(array_merge(self::correos($h['to'] ?? ''), self::correos($h['cc'] ?? ''), self::correos($h['delivered-to'] ?? ''), self::correos($h['x-original-to'] ?? ''), self::correos($h['envelope-to'] ?? '')))),
            'asunto' => trim(self::texto($h['subject'] ?? '')),
            'message_id' => trim($h['message-id'] ?? '', " <>\t") ?: null,
            'automatico' => (isset($h['auto-submitted']) && strtolower(trim($h['auto-submitted'])) !== 'no') || in_array(strtolower(trim($h['precedence'] ?? '')), ['bulk', 'junk', 'auto_reply'], true),
            'texto' => '',
            'adjuntos' => [],
        ];
        self::parte($h, $cuerpo, $out, $nivel);
        $out['texto'] = trim(mb_substr($out['texto'], 0, 4000));
        return $out;
    }

    private static function parte(array $h, string $cuerpo, array &$out, int $nivel): void
    {
        if ($nivel > self::NIVEL_MAX) return;
        [$tipo, $pt] = self::parametros($h['content-type'] ?? 'text/plain');
        $tipo = strtolower($tipo ?: 'text/plain');
        [$disp, $pd] = self::parametros($h['content-disposition'] ?? '');
        $disp = strtolower($disp);

        if (str_starts_with($tipo, 'multipart/') && ! empty($pt['boundary'])) {
            $trozos = explode('--' . $pt['boundary'], $cuerpo);
            array_shift($trozos); // preámbulo
            foreach ($trozos as $t) {
                if (str_starts_with($t, '--')) break; // cierre
                $t = preg_replace('/^[ \t]*\r?\n/', '', $t, 1);
                $t = preg_replace('/\r?\n$/', '', $t, 1);
                [$c, $b] = self::partir($t);
                self::parte(self::cabeceras($c), $b, $out, $nivel + 1);
            }
            return;
        }

        $datos = self::decodificar($cuerpo, strtolower(trim($h['content-transfer-encoding'] ?? '')));

        // Un mail reenviado como adjunto: adentro vienen las facturas.
        if ($tipo === 'message/rfc822') {
            $adentro = self::leer($datos, $nivel + 1);
            foreach ($adentro['adjuntos'] as $a) $out['adjuntos'][] = $a;
            return;
        }

        $nombre = $pd['filename'] ?? $pt['name'] ?? null;
        $nombre = $nombre !== null ? trim(self::texto($nombre)) : null;
        $ext = $nombre ? strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) : '';
        if (in_array($tipo, ['application/octet-stream', 'application/x-pdf', 'binary/octet-stream', 'application/force-download'], true) || ($nombre && ! str_contains($tipo, '/'))) {
            $tipo = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? $tipo;
        }
        if ($tipo === 'image/jpg') $tipo = 'image/jpeg';

        if ($nombre !== null || $disp === 'attachment' || $tipo === 'application/pdf' || str_starts_with($tipo, 'image/')) {
            if ($datos === '') return;
            $out['adjuntos'][] = ['nombre' => $nombre ?: ('adjunto.' . (explode('/', $tipo)[1] ?? 'bin')), 'mime' => $tipo, 'contenido' => $datos, 'en_linea' => $disp === 'inline' || isset($h['content-id'])];
            return;
        }
        if ($tipo === 'text/plain') {
            $cs = strtoupper($pt['charset'] ?? 'UTF-8');
            if ($cs !== 'UTF-8' && $cs !== 'US-ASCII') { $conv = @iconv($cs, 'UTF-8//IGNORE', $datos); if ($conv !== false) $datos = $conv; }
            $out['texto'] .= $datos . "\n";
        }
    }

    // Cabeceras y cuerpo se separan en la primera línea en blanco.
    private static function partir(string $crudo): array
    {
        if (preg_match('/\r?\n\r?\n/', $crudo, $m, PREG_OFFSET_CAPTURE)) return [substr($crudo, 0, $m[0][1]), substr($crudo, $m[0][1] + strlen($m[0][0]))];
        // Sin línea en blanco: o es todo cabecera o todo cuerpo.
        return preg_match('/^[\w\-]+:\s/', $crudo) ? [$crudo, ''] : ['', $crudo];
    }

    private static function cabeceras(string $cab): array
    {
        $cab = preg_replace('/\r?\n[ \t]+/', ' ', $cab); // líneas partidas
        $h = [];
        foreach (preg_split('/\r?\n/', $cab) as $l) {
            if (! preg_match('/^([\w\-]+):\s*(.*)$/', $l, $m)) continue;
            $k = strtolower($m[1]);
            // Los destinatarios se juntan (puede haber varios Delivered-To); del resto vale el primero.
            if (isset($h[$k])) { if (in_array($k, ['to', 'cc', 'delivered-to', 'x-original-to', 'envelope-to'], true)) $h[$k] .= ', ' . $m[2]; continue; }
            $h[$k] = $m[2];
        }
        return $h;
    }

    // "tipo; clave=valor; clave2="valor"" → [tipo, [clave => valor]]. Entiende RFC 2231 (filename*=UTF-8''nombre%20largo.pdf y partes *0, *1).
    private static function parametros(string $v): array
    {
        $trozos = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $v);
        $tipo = trim(array_shift($trozos) ?? '');
        $p = []; $partes = [];
        foreach ($trozos as $t) {
            if (! str_contains($t, '=')) continue;
            [$k, $val] = array_map('trim', explode('=', $t, 2));
            $k = strtolower($k); $val = trim($val, '"');
            if (preg_match('/^([\w\-]+)\*(\d+)?(\*)?$/', $k, $m)) {
                $codificado = ! empty($m[3]) || ($m[2] ?? '') === '';
                $partes[$m[1]][(int) ($m[2] ?? 0)] = [$val, $codificado];
                continue;
            }
            $p[$k] = $val;
        }
        foreach ($partes as $k => $ps) {
            ksort($ps); $junto = ''; $cs = 'UTF-8';
            foreach ($ps as $i => [$val, $cod]) {
                if ($cod) {
                    if ($i === 0 && preg_match("/^([\w\-]*)'[^']*'(.*)$/", $val, $m)) { $cs = strtoupper($m[1] ?: 'UTF-8'); $val = $m[2]; }
                    $val = rawurldecode($val);
                }
                $junto .= $val;
            }
            if ($cs !== 'UTF-8') { $conv = @iconv($cs, 'UTF-8//IGNORE', $junto); if ($conv !== false) $junto = $conv; }
            $p[$k] = $junto;
        }
        return [$tipo, $p];
    }

    private static function decodificar(string $cuerpo, string $cte): string
    {
        return match ($cte) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $cuerpo), false),
            'quoted-printable' => quoted_printable_decode($cuerpo),
            default => $cuerpo,
        };
    }

    // Texto de cabecera con =?UTF-8?B?...?= o =?ISO-8859-1?Q?...?=.
    private static function texto(string $v): string
    {
        if (! str_contains($v, '=?')) return $v;
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $d !== false ? $d : $v;
    }

    private static function correos(string $v): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $v, $m);
        return array_map('strtolower', $m[0]);
    }
}
