<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 86: después del reordenamiento del menú, el tour de bienvenida se vuelve a ofrecer una vez a cada usuario (se saltea con un clic).
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('users')) return;
        DB::table('users')->whereNotNull('business_id')->whereNotNull('tour_visto_en')->update(['tour_visto_en' => null]);
    }

    public function down(): void {}
};
