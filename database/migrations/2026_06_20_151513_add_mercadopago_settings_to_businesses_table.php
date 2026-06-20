<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('mercadopago_settings')->nullable()->after('afip_produccion');
            $table->json('tiendanube_settings')->nullable()->after('mercadopago_settings');
        });
    }
    public function down(): void {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['mercadopago_settings', 'tiendanube_settings']);
        });
    }
};
