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
        Schema::table('pp03_rekening_organisasi', function (Blueprint $table) {
            $table->text('catatan')->nullable()->after('nomor_rekening');
        });

        Schema::table('pp06_rekening_organisasi', function (Blueprint $table) {
            $table->text('catatan')->nullable()->after('nomor_rekening');
        });
    }

    public function down(): void
    {
        Schema::table('pp03_rekening_organisasi', function (Blueprint $table) {
            $table->dropColumn('catatan');
        });

        Schema::table('pp06_rekening_organisasi', function (Blueprint $table) {
            $table->dropColumn('catatan');
        });
    }
};
