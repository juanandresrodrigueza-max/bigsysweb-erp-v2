<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 126: CAEA, el código que ARCA da por adelantado para cada quincena y que se usa cuando ARCA no responde.
return new class extends Migration
{
    const TITULO = 'Si ARCA se cae, igual facturás (CAEA)';

    public function up(): void
    {
        Schema::create('arca_caeas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('cuit', 11);
            $t->boolean('produccion')->default(false);
            $t->unsignedInteger('periodo');           // AAAAMM
            $t->unsignedTinyInteger('orden');          // 1 = del 1 al 15 · 2 = del 16 a fin de mes
            $t->string('caea', 14);
            $t->date('desde'); $t->date('hasta'); $t->date('tope_informar')->nullable();
            $t->json('sin_movimiento')->nullable();    // puntos de venta informados sin movimiento: {pv: fecha}
            $t->timestamps();
            $t->unique(['cuit', 'produccion', 'periodo', 'orden']);
        });
        Schema::table('businesses', fn(Blueprint $t) => $t->boolean('arca_caea')->default(false));
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->string('cae_tipo', 4)->nullable();          // null = CAE · 'CAEA' = emitido en contingencia
            $t->timestamp('caea_informado_en')->nullable(); // cuándo se informó a ARCA (FECAEARegInformativo)
        });

        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'configuracion', 'url' => '/configuracion/puntos-venta',
            'texto' => 'Ahora podés activar la contingencia con CAEA en Configuración → Puntos de venta y ARCA. El sistema le pide a ARCA un código por adelantado para cada quincena. Si ARCA no responde cuando facturás, la factura sale igual, válida, con ese código y en un punto de venta aparte. Cuando ARCA vuelve, se informa sola. Para usarlo hay que dar de alta en ARCA un punto de venta para CAEA.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete();
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['cae_tipo', 'caea_informado_en']));
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('arca_caea'));
        Schema::dropIfExists('arca_caeas');
    }
};
