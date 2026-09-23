<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 13: sueldos, activos fijos, multi-moneda, obras y proyectos, servicio técnico (órdenes de trabajo) y hotelería.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $t) {
            $t->json('verticales_extra')->nullable();      // verticales habilitados además del rubro principal (ej. hotelería)
            $t->json('sueldos')->nullable();               // config: convenio, día de pago, cuenta de pago
        });
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->decimal('total_me', 14, 2)->nullable();    // total en moneda extranjera (cuando moneda != ARS)
            $t->decimal('neto_me', 14, 2)->nullable();
            $t->unsignedBigInteger('proyecto_id')->nullable()->index();
            $t->unsignedBigInteger('orden_trabajo_id')->nullable()->index();
            $t->unsignedBigInteger('estadia_id')->nullable()->index();
        });
        Schema::table('cobro_medios', function (Blueprint $t) {
            $t->string('moneda', 3)->default('ARS');
            $t->decimal('cotizacion', 12, 4)->default(1);
            $t->decimal('monto_me', 14, 2)->nullable();
        });
        Schema::table('pago_medios', function (Blueprint $t) {
            $t->string('moneda', 3)->default('ARS');
            $t->decimal('cotizacion', 12, 4)->default(1);
            $t->decimal('monto_me', 14, 2)->nullable();
        });
        Schema::table('movimientos_fondos', function (Blueprint $t) {
            $t->decimal('cotizacion', 12, 4)->default(1); // para cuentas en moneda extranjera: ingreso/egreso están en esa moneda
        });
        Schema::table('cuentas_fondos', function (Blueprint $t) {
            $t->decimal('cotizacion_cierre', 12, 4)->nullable(); // última cotización con la que se revaluó la tenencia
        });
        Schema::table('expenses', function (Blueprint $t) {
            $t->unsignedBigInteger('proyecto_id')->nullable()->index();
        });

        // ---- Sueldos ----
        Schema::create('empleados', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('business_location_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedInteger('legajo');
            $t->string('nombre', 120);
            $t->string('cuil', 20)->nullable();
            $t->string('categoria', 80)->nullable();
            $t->string('convenio', 80)->nullable();
            $t->string('puesto', 80)->nullable();
            $t->date('fecha_ingreso');
            $t->date('fecha_egreso')->nullable();
            $t->decimal('sueldo_basico', 14, 2)->default(0);
            $t->string('modalidad', 20)->default('mensual'); // mensual | jornal
            $t->string('obra_social', 80)->nullable();
            $t->string('cbu', 30)->nullable();
            $t->string('email', 120)->nullable();
            $t->string('telefono', 40)->nullable();
            $t->boolean('activo')->default(true);
            $t->text('notas')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'legajo']);
        });
        Schema::create('sueldo_conceptos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('codigo', 20);
            $t->string('nombre', 100);
            $t->string('tipo', 20);            // haber | no_remunerativo | deduccion | contribucion
            $t->string('modo', 20);            // porcentaje | fijo
            $t->decimal('valor', 12, 4)->default(0);
            $t->string('base', 20)->default('basico'); // basico | bruto
            $t->boolean('activo')->default(true);
            $t->unsignedSmallInteger('orden')->default(0);
            $t->timestamps();
        });
        Schema::create('liquidaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('periodo', 7);          // YYYY-MM
            $t->string('tipo', 20)->default('mensual'); // mensual | sac | final
            $t->string('estado', 20)->default('borrador'); // borrador | confirmada | pagada
            $t->date('fecha');
            $t->decimal('total_bruto', 14, 2)->default(0);
            $t->decimal('total_no_rem', 14, 2)->default(0);
            $t->decimal('total_deducciones', 14, 2)->default(0);
            $t->decimal('total_neto', 14, 2)->default(0);
            $t->decimal('total_contribuciones', 14, 2)->default(0);
            $t->boolean('importada')->default(false);
            $t->unsignedBigInteger('asiento_id')->nullable();
            $t->timestamp('pagada_en')->nullable();
            $t->text('notas')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'periodo', 'tipo']);
        });
        Schema::create('liquidacion_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('liquidacion_id')->constrained('liquidaciones')->cascadeOnDelete();
            $t->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $t->unsignedSmallInteger('dias')->default(30);
            $t->decimal('horas_extra_50', 8, 2)->default(0);
            $t->decimal('horas_extra_100', 8, 2)->default(0);
            $t->decimal('adicionales', 14, 2)->default(0);     // premios, presentismo extra, etc. (remunerativo)
            $t->decimal('no_rem_extra', 14, 2)->default(0);    // viáticos, etc.
            $t->decimal('anticipos', 14, 2)->default(0);       // adelantos ya pagados
            $t->decimal('bruto', 14, 2)->default(0);
            $t->decimal('no_rem', 14, 2)->default(0);
            $t->decimal('deducciones', 14, 2)->default(0);
            $t->decimal('neto', 14, 2)->default(0);
            $t->decimal('contribuciones', 14, 2)->default(0);
            $t->json('detalle')->nullable();                   // [{codigo,nombre,tipo,monto}]
            $t->timestamps();
        });

        // ---- Activos fijos ----
        Schema::create('activos_fijos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('business_location_id')->nullable();
            $t->string('nombre', 150);
            $t->string('categoria', 30);        // rodados | maquinaria | muebles | equipos | inmuebles | instalaciones | otros
            $t->string('identificacion', 80)->nullable(); // patente, serie
            $t->date('fecha_alta');
            $t->decimal('valor_origen', 14, 2);
            $t->decimal('valor_residual', 14, 2)->default(0);
            $t->unsignedSmallInteger('vida_util_meses');
            $t->decimal('amortizado', 14, 2)->default(0);  // acumulado
            $t->string('estado', 20)->default('activo');    // activo | baja | vendido
            $t->date('fecha_baja')->nullable();
            $t->decimal('valor_baja', 14, 2)->nullable();
            $t->unsignedBigInteger('comprobante_id')->nullable();
            $t->unsignedBigInteger('proyecto_id')->nullable();
            $t->text('notas')->nullable();
            $t->timestamps();
        });
        Schema::create('activo_amortizaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('activo_fijo_id')->constrained('activos_fijos')->cascadeOnDelete();
            $t->string('periodo', 7);
            $t->decimal('monto', 14, 2);
            $t->unsignedBigInteger('asiento_id')->nullable();
            $t->timestamps();
            $t->unique(['activo_fijo_id', 'periodo']);
        });

        // ---- Obras y proyectos ----
        Schema::create('proyectos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('business_location_id')->nullable();
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedBigInteger('responsable_id')->nullable();
            $t->string('codigo', 20);
            $t->string('nombre', 150);
            $t->text('descripcion')->nullable();
            $t->string('direccion', 200)->nullable();
            $t->string('estado', 20)->default('presupuestado'); // presupuestado | en_curso | pausado | terminado | cancelado
            $t->date('fecha_inicio')->nullable();
            $t->date('fecha_fin_prevista')->nullable();
            $t->date('fecha_fin')->nullable();
            $t->decimal('presupuesto_venta', 14, 2)->default(0);
            $t->decimal('presupuesto_costo', 14, 2)->default(0);
            $t->decimal('avance', 5, 2)->default(0);           // % físico
            $t->decimal('avance_certificado', 5, 2)->default(0); // % ya facturado
            $t->text('notas')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'codigo']);
        });
        Schema::create('proyecto_partes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('empleado_id')->nullable();
            $t->date('fecha');
            $t->string('tipo', 20);           // material | mano_obra | maquinaria | gasto | subcontrato
            $t->string('descripcion', 200);
            $t->decimal('cantidad', 12, 3)->default(1);
            $t->string('unidad', 10)->nullable();
            $t->decimal('costo_unit', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->unsignedBigInteger('stock_movement_id')->nullable();
            $t->timestamps();
        });

        // ---- Servicio técnico / órdenes de trabajo ----
        Schema::create('ordenes_trabajo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('business_location_id')->nullable();
            $t->unsignedInteger('numero');
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->string('nombre', 120)->nullable();
            $t->string('telefono', 40)->nullable();
            $t->string('equipo', 120);
            $t->string('marca_modelo', 120)->nullable();
            $t->string('serie', 80)->nullable();
            $t->text('falla');
            $t->text('diagnostico')->nullable();
            $t->string('estado', 20)->default('recibido'); // recibido | diagnostico | presupuestado | aprobado | en_curso | listo | entregado | cancelado
            $t->string('prioridad', 10)->default('normal');
            $t->unsignedBigInteger('tecnico_id')->nullable();
            $t->unsignedBigInteger('proyecto_id')->nullable();
            $t->date('fecha_ingreso');
            $t->date('fecha_prometida')->nullable();
            $t->timestamp('entregado_en')->nullable();
            $t->decimal('presupuesto', 14, 2)->default(0);
            $t->timestamp('aprobado_en')->nullable();
            $t->unsignedBigInteger('comprobante_id')->nullable();
            $t->text('firma')->nullable();               // firma del cliente (dataURL)
            $t->string('firma_nombre', 120)->nullable();
            $t->string('token', 40)->unique();
            $t->text('notas')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'numero']);
        });
        Schema::create('orden_trabajo_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('orden_trabajo_id')->constrained('ordenes_trabajo')->cascadeOnDelete();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('tipo', 20)->default('material'); // material | mano_obra
            $t->string('descripcion', 200);
            $t->decimal('cantidad', 12, 3)->default(1);
            $t->decimal('precio_unit', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('orden_trabajo_tareas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('orden_trabajo_id')->constrained('ordenes_trabajo')->cascadeOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('descripcion', 200);
            $t->boolean('hecha')->default(false);
            $t->timestamp('hecha_en')->nullable();
            $t->timestamps();
        });

        // ---- Hotelería ----
        Schema::create('habitaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('business_location_id')->nullable();
            $t->string('nombre', 40);
            $t->string('tipo', 30)->default('doble');   // single | doble | triple | suite | cabaña | dormi
            $t->unsignedSmallInteger('capacidad')->default(2);
            $t->decimal('tarifa', 14, 2)->default(0);   // por noche
            $t->string('piso', 20)->nullable();
            $t->string('estado', 20)->default('libre');  // libre | ocupada | limpieza | mantenimiento
            $t->boolean('activa')->default(true);
            $t->unsignedSmallInteger('orden')->default(0);
            $t->timestamps();
        });
        Schema::create('estadias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('business_location_id')->nullable();
            $t->foreignId('habitacion_id')->constrained('habitaciones')->cascadeOnDelete();
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->string('nombre', 120);
            $t->string('telefono', 40)->nullable();
            $t->string('email', 120)->nullable();
            $t->string('documento', 30)->nullable();
            $t->unsignedSmallInteger('personas')->default(2);
            $t->date('desde');
            $t->date('hasta');
            $t->string('estado', 20)->default('reservada'); // reservada | checkin | checkout | cancelada | no_show
            $t->decimal('tarifa_noche', 14, 2)->default(0);
            $t->decimal('senia', 14, 2)->default(0);
            $t->string('origen', 20)->default('manual');    // manual | web | booking | airbnb
            $t->unsignedBigInteger('comprobante_id')->nullable();
            $t->timestamp('checkin_en')->nullable();
            $t->timestamp('checkout_en')->nullable();
            $t->string('token', 40)->unique();
            $t->text('notas')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'desde', 'hasta']);
        });
        Schema::create('estadia_consumos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('estadia_id')->constrained('estadias')->cascadeOnDelete();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->date('fecha');
            $t->string('descripcion', 200);
            $t->decimal('cantidad', 12, 3)->default(1);
            $t->decimal('precio_unit', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['estadia_consumos', 'estadias', 'habitaciones', 'orden_trabajo_tareas', 'orden_trabajo_items', 'ordenes_trabajo', 'proyecto_partes', 'proyectos', 'activo_amortizaciones', 'activos_fijos', 'liquidacion_items', 'liquidaciones', 'sueldo_conceptos', 'empleados'] as $tabla) Schema::dropIfExists($tabla);
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn(['verticales_extra', 'sueldos']));
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['total_me', 'neto_me', 'proyecto_id', 'orden_trabajo_id', 'estadia_id']));
        Schema::table('cobro_medios', fn(Blueprint $t) => $t->dropColumn(['moneda', 'cotizacion', 'monto_me']));
        Schema::table('pago_medios', fn(Blueprint $t) => $t->dropColumn(['moneda', 'cotizacion', 'monto_me']));
        Schema::table('movimientos_fondos', fn(Blueprint $t) => $t->dropColumn('cotizacion'));
        Schema::table('cuentas_fondos', fn(Blueprint $t) => $t->dropColumn('cotizacion_cierre'));
        Schema::table('expenses', fn(Blueprint $t) => $t->dropColumn('proyecto_id'));
    }
};
