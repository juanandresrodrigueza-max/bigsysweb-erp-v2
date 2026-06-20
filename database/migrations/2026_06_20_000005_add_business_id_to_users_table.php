<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable()->after('id');
            $table->string('status')->default('active')->after('email');
            $table->string('language')->default('es')->after('status');
            $table->string('avatar')->nullable()->after('language');
            $table->boolean('is_superadmin')->default(false)->after('avatar');
            $table->foreign('business_id')->references('id')->on('businesses')->nullOnDelete();
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn(['business_id', 'status', 'language', 'avatar', 'is_superadmin']);
        });
    }
};
