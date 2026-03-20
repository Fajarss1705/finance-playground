<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pp03_rekening_organisasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp03_data_id')->constrained('pp03_data')->cascadeOnDelete();
            $table->string('nama_bank', 255);
            $table->string('nama_rekening', 255);
            $table->string('nomor_rekening', 50);
            $table->timestamps();
        });

        Schema::create('pp06_rekening_organisasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp06_periode_tahunan_id')->constrained('pp06_periode_tahunan')->cascadeOnDelete();
            $table->string('nama_bank', 255);
            $table->string('nama_rekening', 255);
            $table->string('nomor_rekening', 50);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pp06_rekening_organisasi');
        Schema::dropIfExists('pp03_rekening_organisasi');
    }
};
