<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->enum('status', ['new','contacted','qualified','proposal','negotiation','won','lost'])->default('new');
            $table->string('source')->nullable();
            $table->decimal('value', 14, 2)->default(0);
            $table->string('currency', 3)->default('ARS');
            $table->tinyInteger('probability')->default(0);
            $table->date('expected_close_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_id','status']);
        });
    }
    public function down(): void { Schema::dropIfExists('crm_leads'); }
};
