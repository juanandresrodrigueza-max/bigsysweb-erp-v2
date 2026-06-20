<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('user_id');
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('business_id');
            $table->index(['business_id', 'expense_date']);
        });
    }
    public function down(): void { Schema::dropIfExists('expenses'); }
};
