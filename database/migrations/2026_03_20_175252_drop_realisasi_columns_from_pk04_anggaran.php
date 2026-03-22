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
        Schema::table('pk04_anggaran', function (Blueprint $table) {
            $table->dropColumn(['nominal_realisasi', 'status_realisasi', 'prbl_workflow_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pk04_anggaran', function (Blueprint $table) {
            $table->decimal('nominal_realisasi', 20, 2)->nullable();
            $table->string('status_realisasi')->nullable();
            $table->unsignedBigInteger('prbl_workflow_id')->nullable();
        });
    }
};
