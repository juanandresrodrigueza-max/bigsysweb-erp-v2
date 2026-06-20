<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('business_location_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('pending');
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('business_id');
            $table->index('contact_id');
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'payment_status']);
        });
    }
    public function down(): void { Schema::dropIfExists('purchases'); }
};
