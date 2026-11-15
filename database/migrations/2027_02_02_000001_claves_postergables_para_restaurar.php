<?php

use App\Services\Migracion\BackupService;
use Illuminate\Database\Migrations\Migration;

// Las copias de seguridad no se podían restaurar (el ensayo nocturno fallaba en Corralón Demo, La Espiga y Prueba E2E): restaurar
// carga tabla por tabla y hay claves que apuntan a una tabla que se carga después. Las claves pasan a DEFERRABLE INITIALLY IMMEDIATE:
// en el uso diario se controlan al momento, igual que antes; sólo la restauración posterga el control hasta el final. Ver
// BackupService::postergarClaves(). (Parche de la sesión CRM Producción, probado con las copias reales del servidor.)
return new class extends Migration
{
    public function up(): void
    {
        BackupService::postergarClaves(); // fuera de una transacción el SET CONSTRAINTS del final no tiene efecto: sólo quedan las claves
    }

    public function down(): void {}
};
