<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pabd02a_item_perubahan', function (Blueprint $table) {
            $table->decimal('nominal_awal', 15, 2)->nullable()->after('bulan_tujuan');
        });

        // Backfill: for already-approved tarik_maju items the original anggaran was zeroed,
        // but the new tarik_maju anggaran (previous_anggaran_id = old id) preserves the nominal.
        DB::statement('
            UPDATE pabd02a_item_perubahan
            SET nominal_awal = (
                SELECT a.nominal_anggaran
                FROM pk04_anggaran a
                WHERE a.previous_anggaran_id = pabd02a_item_perubahan.pk04_anggaran_id
                  AND a.source = \'tarik_maju\'
                LIMIT 1
            )
            WHERE tipe_perubahan = \'tarik_maju\'
              AND nominal_awal IS NULL
        ');

        // For items where the original anggaran is still intact (not yet zeroed),
        // copy the current nominal directly.
        DB::statement('
            UPDATE pabd02a_item_perubahan
            SET nominal_awal = (
                SELECT a.nominal_anggaran
                FROM pk04_anggaran a
                WHERE a.id = pabd02a_item_perubahan.pk04_anggaran_id
            )
            WHERE tipe_perubahan = \'tarik_maju\'
              AND nominal_awal IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('pabd02a_item_perubahan', function (Blueprint $table) {
            $table->dropColumn('nominal_awal');
        });
    }
};
