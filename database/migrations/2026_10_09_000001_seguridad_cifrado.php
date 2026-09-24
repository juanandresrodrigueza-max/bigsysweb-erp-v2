<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 17 · Seguridad: credenciales de terceros (Mercado Pago, Tiendanube, WhatsApp, canales) cifradas en la base.
return new class extends Migration
{
    private const COLUMNAS = ['businesses' => ['mercadopago_settings', 'tiendanube_settings', 'whatsapp_settings'], 'canales' => ['credenciales']];

    public function up(): void
    {
        // 'pre_restauracion' no entraba en backups.origen (varchar 15): PostgreSQL lo rechaza, SQLite lo dejaba pasar.
        if (DB::getDriverName() !== 'sqlite' && Schema::hasTable('backups')) Schema::table('backups', fn(Blueprint $t) => $t->string('origen', 30)->change());
        foreach (self::COLUMNAS as $tabla => $cols) {
            if (! Schema::hasTable($tabla)) continue;
            // El texto cifrado no es JSON: la columna pasa a texto (en SQLite ya lo es).
            if (DB::getDriverName() !== 'sqlite') Schema::table($tabla, function (Blueprint $t) use ($cols) { foreach ($cols as $c) $t->text($c)->nullable()->change(); });
            foreach (DB::table($tabla)->select(array_merge(['id'], $cols))->cursor() as $row) {
                $upd = [];
                foreach ($cols as $c) {
                    $v = $row->$c;
                    if ($v === null || $v === '' || self::cifrado($v)) continue;
                    $upd[$c] = Crypt::encryptString(is_string($v) && json_decode($v) !== null ? $v : json_encode($v));
                }
                if ($upd) DB::table($tabla)->where('id', $row->id)->update($upd);
            }
        }
    }

    private static function cifrado(string $v): bool
    {
        $d = json_decode(base64_decode($v, true) ?: '', true);
        return is_array($d) && isset($d['iv'], $d['value'], $d['mac']);
    }

    public function down(): void
    {
        foreach (self::COLUMNAS as $tabla => $cols) {
            if (! Schema::hasTable($tabla)) continue;
            foreach (DB::table($tabla)->select(array_merge(['id'], $cols))->cursor() as $row) {
                $upd = [];
                foreach ($cols as $c) if ($row->$c && self::cifrado($row->$c)) $upd[$c] = Crypt::decryptString($row->$c);
                if ($upd) DB::table($tabla)->where('id', $row->id)->update($upd);
            }
        }
    }
};
