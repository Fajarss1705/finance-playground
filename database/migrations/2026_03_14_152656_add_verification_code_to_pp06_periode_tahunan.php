<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pp06_periode_tahunan', function (Blueprint $table) {
            $table->string('verification_code', 12)->nullable()->after('tanggal_penetapan_program');
            $table->index('verification_code');
        });
    }

    public function down(): void
    {
        Schema::table('pp06_periode_tahunan', function (Blueprint $table) {
            $table->dropIndex(['verification_code']);
            $table->dropColumn('verification_code');
        });
    }
};
