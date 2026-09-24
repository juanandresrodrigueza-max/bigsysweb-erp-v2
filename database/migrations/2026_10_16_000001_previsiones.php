<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Previsiones: gastos e ingresos que se repiten todos los meses o cada X meses (alquiler, seguros, impuestos, cuotas, abonos que cobramos por fuera).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('previsiones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->foreignId('business_location_id')->nullable()->constrained('business_locations')->nullOnDelete();
            $t->string('tipo', 10); // egreso | ingreso
            $t->string('descripcion', 160);
            $t->decimal('monto', 14, 2);
            $t->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $t->foreignId('cuenta_fondos_id')->nullable()->constrained('cuentas_fondos')->nullOnDelete();
            $t->unsignedSmallInteger('cada_meses')->default(1); // 1 = todos los meses, 2 = bimestral, 3, 6, 12…
            $t->unsignedTinyInteger('dia')->default(10); // día del mes en que vence
            $t->date('desde');
            $t->date('hasta')->nullable();
            $t->date('proximo')->nullable();
            $t->boolean('registrar_auto')->default(false); // el día del vencimiento se registra solo en fondos
            $t->unsignedTinyInteger('avisar_dias')->default(3);
            $t->boolean('activo')->default(true);
            $t->text('notas')->nullable();
            $t->timestamp('ultimo_registrado_en')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'activo', 'proximo']);
        });
        Schema::table('movimientos_fondos', fn(Blueprint $t) => $t->foreignId('prevision_id')->nullable()->after('origen_id')->constrained('previsiones')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('movimientos_fondos', fn(Blueprint $t) => $t->dropConstrainedForeignId('prevision_id'));
        Schema::dropIfExists('previsiones');
    }
};
