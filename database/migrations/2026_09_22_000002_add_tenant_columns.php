<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'sales', 'stock_movements', 'customers'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                if (! Schema::hasColumn($tabla, 'business_id')) {
                    $table->foreignId('business_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
                }
                if (! Schema::hasColumn($tabla, 'business_location_id')) {
                    $table->foreignId('business_location_id')->nullable()->after('business_id')->constrained()->nullOnDelete();
                }
            });
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'business_location_id')) {
                $table->foreignId('business_location_id')->nullable()->after('business_id')->constrained()->nullOnDelete();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('business_id');
            $table->foreignId('current_location_id')->nullable()->after('role_id');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role_id', 'current_location_id', 'last_login_at']);
        });
        foreach (['products', 'sales', 'stock_movements', 'customers', 'expenses'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                if (Schema::hasColumn($tabla, 'business_location_id')) {
                    $table->dropConstrainedForeignId('business_location_id');
                }
                if ($tabla !== 'expenses' && Schema::hasColumn($tabla, 'business_id')) {
                    $table->dropConstrainedForeignId('business_id');
                }
            });
        }
    }
};
