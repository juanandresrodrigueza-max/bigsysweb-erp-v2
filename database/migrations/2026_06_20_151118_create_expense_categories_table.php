<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('name');
            $table->string('color', 10)->nullable();
            $table->timestamps();
            $table->index('business_id');
        });
    }
    public function down(): void { Schema::dropIfExists('expense_categories'); }
};
