<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('logo')->nullable();
            $table->string('currency', 10)->default('ARS');
            $table->string('country', 5)->default('AR');
            $table->string('timezone')->default('America/Argentina/Buenos_Aires');
            $table->string('locale')->default('es_AR');
            $table->string('date_format')->default('d/m/Y');
            $table->string('time_format')->default('H:i');
            $table->string('financial_year_start_month', 2)->default('01');
            $table->string('cuit', 20)->nullable()->unique();
            $table->string('razon_social')->nullable();
            $table->string('condicion_iva', 50)->nullable();
            $table->string('afip_punto_venta', 10)->nullable();
            $table->string('afip_cert_path')->nullable();
            $table->string('afip_key_path')->nullable();
            $table->boolean('afip_produccion')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('owner_id');
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('businesses'); }
};
