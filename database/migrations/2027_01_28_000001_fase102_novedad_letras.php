<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 102: aviso en la campanita: la letra de la factura como la pide ARCA (A al monotributista).
return new class extends Migration
{
    private const TITULO = 'Facturación: la letra como la pide ARCA (A también al monotributista)';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'comprobantes', 'url' => '/ayuda/facturar',
            'texto' => 'Revisamos la letra de cada comprobante contra las normas de ARCA. Si sos responsable inscripto, a los monotributistas les sale Factura A con el IVA discriminado y la leyenda de la ley 27.618 (así lo pide la RG 5003 desde julio de 2021; antes salía B). La B queda para exentos, consumidores finales, no categorizados e IVA no alcanzado; el monotributista o exento emite C; al exterior, E. Antes de pedir el CAE, el sistema frena lo que ARCA rechazaría: la A sin CUIT del cliente y el consumidor final sin DNI por encima de $ 10.000.000. En la ficha del cliente hay más condiciones de IVA. Revisá que la condición de tus clientes esté bien cargada: el botón de ARCA la trae del padrón.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
