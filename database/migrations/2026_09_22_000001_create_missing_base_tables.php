<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contacts')) {
            Schema::create('contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('type', 20)->default('customer'); // customer | supplier | both
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('mobile', 50)->nullable();
                $table->string('document_type', 20)->nullable();
                $table->string('document', 30)->nullable();
                $table->string('cuit', 20)->nullable();
                $table->string('condicion_iva', 50)->nullable();
                $table->text('address')->nullable();
                $table->string('city')->nullable();
                $table->string('province')->nullable();
                $table->string('postal_code', 20)->nullable();
                $table->decimal('credit_limit', 14, 2)->default(0);
                $table->decimal('balance', 14, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['business_id', 'type']);
            });
        }

        if (! Schema::hasTable('tax_rates')) {
            Schema::create('tax_rates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->decimal('rate', 8, 4);
                $table->string('type', 30)->default('iva');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('type', 30)->default('efectivo');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('invoice_sequences')) {
            Schema::create('invoice_sequences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('type', 10);
                $table->string('prefix', 10)->nullable();
                $table->unsignedBigInteger('next_number')->default(1);
                $table->unsignedTinyInteger('padding')->default(8);
                $table->timestamps();
                $table->unique(['business_id', 'type', 'prefix']);
            });
        }

        if (! Schema::hasTable('cash_registers')) {
            Schema::create('cash_registers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cash_register_sessions')) {
            Schema::create('cash_register_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('cash_register_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('status', 20)->default('open');
                $table->decimal('opening_amount', 14, 2)->default(0);
                $table->decimal('closing_amount', 14, 2)->nullable();
                $table->decimal('expected_amount', 14, 2)->nullable();
                $table->decimal('difference', 14, 2)->nullable();
                $table->text('closing_notes')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_sessions');
        Schema::dropIfExists('cash_registers');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('contacts');
    }
};
