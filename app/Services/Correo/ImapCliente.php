<?php

namespace App\Services\Correo;

// Fase 120.3: lo mínimo de IMAP para vaciar una casilla: entrar, ver lo no leído, bajar cada mensaje y marcarlo leído.
// Habla directo por el socket (no necesita la extensión imap de PHP). En las pruebas se le pasa una conexión de mentira.
class ImapCliente
{
    private int $n = 0;

    // $conexion: cualquier objeto con escribir(string): void, linea(): string|false y bytes(int): string.
    public function __construct(private object $conexion) {}

    public static function abrir(string $host, int $puerto = 993, int $espera = 20): self
    {
        $directo = $puerto === 993;
        $s = @stream_socket_client(($directo ? 'ssl://' : 'tcp://') . $host . ':' . $puerto, $errno, $error, $espera);
        if (! $s) throw new \RuntimeException("No se pudo conectar a {$host}:{$puerto}" . ($error ? " ({$error})" : ''));
        stream_set_timeout($s, $espera);
        $c = new self(new ConexionSocket($s));
        $saludo = $c->conexion->linea();
        if ($saludo === false || ! preg_match('/^\* (OK|PREAUTH)/i', $saludo)) throw new \RuntimeException('El servidor de correo no contestó como un servidor IMAP.');
        if (! $directo) { // puerto 143: se pasa a conexión cifrada antes de mandar la clave
            if (! $c->comando('STARTTLS')['ok'] || ! @stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new \RuntimeException('El servidor no aceptó la conexión cifrada (probá con el puerto 993).');
        }
        return $c;
    }

    public function entrar(string $usuario, string $clave, string $carpeta = 'INBOX'): void
    {
        $r = $this->comando('LOGIN ' . self::comillas($usuario) . ' ' . self::comillas($clave));
        if (! $r['ok']) throw new \RuntimeException('El servidor de correo rechazó el usuario o la clave.');
        if (! $this->comando('SELECT ' . self::comillas($carpeta))['ok']) throw new \RuntimeException("No se pudo abrir la carpeta {$carpeta}.");
    }

    // Los identificadores (UID) de los mensajes sin leer, del más viejo al más nuevo.
    public function sinLeer(int $max = 20): array
    {
        $r = $this->comando('UID SEARCH UNSEEN');
        if (! $r['ok']) return [];
        $uids = [];
        foreach ($r['lineas'] as $l) if (preg_match('/^\* SEARCH(.*)$/i', $l, $m)) foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) as $u) $uids[] = (int) $u;
        sort($uids);
        return array_slice($uids, 0, $max);
    }

    // El mensaje entero, sin marcarlo como leído (eso se hace después de procesarlo bien).
    public function mensaje(int $uid): ?string
    {
        $r = $this->comando("UID FETCH {$uid} (BODY.PEEK[])");
        return $r['ok'] ? ($r['literales'][0] ?? null) : null;
    }

    public function marcarLeido(int $uid): void { $this->comando("UID STORE {$uid} +FLAGS.SILENT (\\Seen)"); }

    public function salir(): void { try { $this->comando('LOGOUT'); } catch (\Throwable $e) { /* ya se cortó */ } }

    // Manda un comando y junta la respuesta hasta la línea con la etiqueta. Los bloques {n} (literales) se leen aparte.
    private function comando(string $cmd): array
    {
        $tag = 'B' . str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT);
        $this->conexion->escribir("{$tag} {$cmd}\r\n");
        $lineas = []; $literales = [];
        while (true) {
            $l = $this->conexion->linea();
            if ($l === false) throw new \RuntimeException('Se cortó la conexión con el servidor de correo.');
            $l = rtrim($l, "\r\n");
            if (str_starts_with($l, $tag . ' ')) return ['ok' => (bool) preg_match('/^' . $tag . ' OK/i', $l), 'lineas' => $lineas, 'literales' => $literales, 'final' => $l];
            if (preg_match('/\{(\d+)\}$/', $l, $m)) $literales[] = $this->conexion->bytes((int) $m[1]);
            $lineas[] = $l;
        }
    }

    private static function comillas(string $v): string { return '"' . addcslashes($v, "\\\"") . '"'; }
}
