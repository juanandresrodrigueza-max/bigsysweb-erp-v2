<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

// Dos factores TOTP (RFC 6238) sin dependencias: sirve con Google Authenticator, Authy, Microsoft Authenticator, etc.
class TotpService
{
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generarSecreto(int $bytes = 20): string
    {
        $bin = random_bytes($bytes); $out = ''; $bits = '';
        foreach (str_split($bin) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        foreach (str_split($bits, 5) as $chunk) $out .= self::ALFABETO[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        return $out;
    }

    private function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $s)); $bits = '';
        foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos(self::ALFABETO, $c)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
        return $out;
    }

    public function codigo(string $secreto, ?int $ts = null, int $periodo = 30, int $digitos = 6): string
    {
        $contador = (int) floor(($ts ?? time()) / $periodo);
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $contador), $this->base32Decode($secreto), true);
        $offset = ord($hash[19]) & 0xf;
        $code = ((ord($hash[$offset]) & 0x7f) << 24 | (ord($hash[$offset + 1]) & 0xff) << 16 | (ord($hash[$offset + 2]) & 0xff) << 8 | (ord($hash[$offset + 3]) & 0xff)) % (10 ** $digitos);
        return str_pad((string) $code, $digitos, '0', STR_PAD_LEFT);
    }

    // Acepta el código actual y los de ±1 ventana (relojes desfasados).
    public function verificar(string $secreto, string $codigo): bool
    {
        $codigo = preg_replace('/\D/', '', $codigo);
        if (strlen($codigo) !== 6) return false;
        foreach ([-1, 0, 1] as $w) if (hash_equals($this->codigo($secreto, time() + $w * 30), $codigo)) return true;
        return false;
    }

    public function uri(User $u, string $secreto): string
    {
        $emisor = rawurlencode('BigSysWeb');
        return "otpauth://totp/{$emisor}:" . rawurlencode($u->email) . "?secret={$secreto}&issuer={$emisor}&algorithm=SHA1&digits=6&period=30";
    }

    public function activar(User $u, string $secreto): array
    {
        $codigos = collect(range(1, 8))->map(fn() => strtoupper(Str::random(5)) . '-' . strtoupper(Str::random(5)))->all();
        $u->forceFill(['two_factor_secret' => Crypt::encryptString($secreto), 'two_factor_recovery_codes' => Crypt::encryptString(json_encode($codigos)), 'two_factor_enabled_at' => now()])->save();
        return $codigos;
    }

    public function desactivar(User $u): void
    {
        $u->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_enabled_at' => null])->save();
    }

    // Verifica un código TOTP o uno de recuperación (que se consume).
    public function validarLogin(User $u, string $codigo): bool
    {
        if (! $u->two_factor_secret) return true;
        if ($this->verificar(Crypt::decryptString($u->two_factor_secret), $codigo)) return true;
        $rec = json_decode(Crypt::decryptString($u->two_factor_recovery_codes ?? Crypt::encryptString('[]')), true) ?: [];
        $k = array_search(strtoupper(trim($codigo)), $rec, true);
        if ($k === false) return false;
        unset($rec[$k]);
        $u->forceFill(['two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($rec)))])->save();
        return true;
    }

    public function codigosRecuperacion(User $u): array
    {
        return $u->two_factor_recovery_codes ? (json_decode(Crypt::decryptString($u->two_factor_recovery_codes), true) ?: []) : [];
    }
}
