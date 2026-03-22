<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prbl_workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('pabd_workflow_id')->constrained('pabd_workflows');
            $table->foreignId('pp_workflow_id')->constrained('pp_workflows');
            $table->smallInteger('bulan_laporan');
            $table->smallInteger('tahun_laporan');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users');
            $table->foreignId('created_by_role_id')->nullable()->constrained('roles');
            $table->foreignId('created_by_team_id')->nullable()->constrained('teams');
            $table->foreignId('created_by_org_id')->nullable()->constrained('organizations');
            $table->jsonb('history')->default('[]');
            $table->timestamps();
            $table->softDeletes();
        });

        // PRBL01 — Laporan Kegiatan + Realisasi
        Schema::create('prbl01_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl_workflow_id')->constrained('prbl_workflows');
            $table->timestamps();
        });

        Schema::create('prbl01_item_kegiatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl01_data_id')->constrained('prbl01_data')->cascadeOnDelete();
            $table->foreignId('pk04_kegiatan_id')->constrained('pk04_kegiatan');
            $table->text('masalah')->nullable();
            $table->text('langkah_penanganan')->nullable();
            $table->text('harapan')->nullable();
            $table->text('catatan_tim')->nullable();
            $table->timestamps();
        });

        Schema::create('prbl01_foto_kegiatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl01_item_kegiatan_id')->constrained('prbl01_item_kegiatan')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files');
            $table->timestamps();
        });

        Schema::create('prbl01_nota_pengeluaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl01_item_kegiatan_id')->constrained('prbl01_item_kegiatan')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files');
            $table->timestamps();
        });

        Schema::create('prbl01_item_kuisioner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl01_item_kegiatan_id')->constrained('prbl01_item_kegiatan')->cascadeOnDelete();
            $table->foreignId('pk04_kuisioner_id')->constrained('pk04_kuisioner');
            $table->text('jawaban')->nullable();
            $table->timestamps();
        });

        Schema::create('prbl01_item_realisasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl01_data_id')->constrained('prbl01_data')->cascadeOnDelete();
            $table->foreignId('pk04_anggaran_id')->constrained('pk04_anggaran');
            $table->decimal('nominal_realisasi', 20, 2)->default(0);
            $table->text('komentar_realisasi')->nullable();
            $table->timestamps();
        });

        // PRBL02A + PRBL02B — No data tables (approval/reject tracked in history only)

        // PRBL03 — Refund Calculation
        Schema::create('prbl03_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl_workflow_id')->constrained('prbl_workflows');
            $table->decimal('nominal_refund', 20, 2)->default(0);
            $table->text('keterangan')->nullable();
            $table->timestamps();
        });

        Schema::create('prbl03_bukti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl03_data_id')->constrained('prbl03_data')->cascadeOnDelete();
            $table->string('tipe'); // 'bukti_transfer', 'foto_nota'
            $table->foreignId('file_id')->constrained('files');
            $table->timestamps();
        });

        // PRBL04 — No data table (approval/reject tracked in history only)

        // PRBL05 — Final Compile (Laporan Bulanan)
        Schema::create('prbl05_laporan_bulanan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl_workflow_id')->constrained('prbl_workflows');
            $table->string('verification_code', 8)->nullable();

            // PRBL01 Author Snapshot
            $table->string('prbl01_created_by_user_name');
            $table->string('prbl01_created_by_role_name');
            $table->string('prbl01_created_by_team_name');
            $table->string('prbl01_created_by_organization_name');
            $table->string('prbl01_created_by_workspace_name');
            $table->timestamp('prbl01_created_at');

            // PRBL02A Approver Snapshot (narasi reviewer)
            $table->string('prbl02a_approved_by_user_name');
            $table->string('prbl02a_approved_by_role_name');
            $table->string('prbl02a_approved_by_team_name');
            $table->string('prbl02a_approved_by_organization_name');
            $table->string('prbl02a_approved_by_workspace_name');
            $table->timestamp('prbl02a_approved_at');

            // PRBL02B Approver Snapshot (anggaran reviewer)
            $table->string('prbl02b_approved_by_user_name');
            $table->string('prbl02b_approved_by_role_name');
            $table->string('prbl02b_approved_by_team_name');
            $table->string('prbl02b_approved_by_organization_name');
            $table->string('prbl02b_approved_by_workspace_name');
            $table->timestamp('prbl02b_approved_at');

            // PRBL03 Author Snapshot (refund submitter)
            $table->string('prbl03_created_by_user_name');
            $table->string('prbl03_created_by_role_name');
            $table->string('prbl03_created_by_team_name');
            $table->string('prbl03_created_by_organization_name');
            $table->string('prbl03_created_by_workspace_name');
            $table->timestamp('prbl03_created_at');

            // PRBL04 Reviewer Snapshot (final reviewer)
            $table->string('prbl04_approved_by_user_name');
            $table->string('prbl04_approved_by_role_name');
            $table->string('prbl04_approved_by_team_name');
            $table->string('prbl04_approved_by_organization_name');
            $table->string('prbl04_approved_by_workspace_name');
            $table->timestamp('prbl04_approved_at');

            // PRBL03 Refund Data
            $table->text('keterangan')->nullable();

            // Totals
            $table->decimal('total_anggaran_dicairkan', 20, 2);
            $table->decimal('total_realisasi', 20, 2);
            $table->decimal('total_refund', 20, 2);
            $table->integer('total_item');

            $table->timestamps();
        });

        Schema::create('prbl05_item_kegiatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl05_laporan_bulanan_id')->constrained('prbl05_laporan_bulanan')->cascadeOnDelete();
            $table->foreignId('pk04_kegiatan_id')->constrained('pk04_kegiatan');
            $table->text('masalah')->nullable();
            $table->text('langkah_penanganan')->nullable();
            $table->text('harapan')->nullable();
            $table->text('catatan_tim')->nullable();
            $table->timestamps();
        });

        Schema::create('prbl05_foto_kegiatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl05_item_kegiatan_id')->constrained('prbl05_item_kegiatan')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files');
            $table->timestamps();
        });

        Schema::create('prbl05_nota_pengeluaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl05_item_kegiatan_id')->constrained('prbl05_item_kegiatan')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files');
            $table->timestamps();
        });

        Schema::create('prbl05_item_kuisioner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl05_item_kegiatan_id')->constrained('prbl05_item_kegiatan')->cascadeOnDelete();
            $table->foreignId('pk04_kuisioner_id')->constrained('pk04_kuisioner');
            $table->text('jawaban')->nullable();
            $table->timestamps();
        });

        Schema::create('prbl05_item_realisasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl05_laporan_bulanan_id')->constrained('prbl05_laporan_bulanan')->cascadeOnDelete();
            $table->foreignId('pk04_anggaran_id')->constrained('pk04_anggaran');
            $table->decimal('nominal_anggaran', 20, 2);
            $table->decimal('nominal_realisasi', 20, 2);
            $table->decimal('selisih', 20, 2);
            $table->text('komentar_realisasi')->nullable();
            $table->timestamps();
        });

        Schema::create('prbl05_rekening_organisasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl05_laporan_bulanan_id')->constrained('prbl05_laporan_bulanan')->cascadeOnDelete();
            $table->string('nama_bank');
            $table->string('nama_rekening');
            $table->string('nomor_rekening', 50);
            $table->timestamps();
        });

        Schema::create('prbl05_bukti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prbl05_laporan_bulanan_id')->constrained('prbl05_laporan_bulanan')->cascadeOnDelete();
            $table->string('tipe'); // 'bukti_transfer', 'foto_nota'
            $table->foreignId('file_id')->constrained('files');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prbl05_bukti');
        Schema::dropIfExists('prbl05_rekening_organisasi');
        Schema::dropIfExists('prbl05_item_realisasi');
        Schema::dropIfExists('prbl05_item_kuisioner');
        Schema::dropIfExists('prbl05_nota_pengeluaran');
        Schema::dropIfExists('prbl05_foto_kegiatan');
        Schema::dropIfExists('prbl05_item_kegiatan');
        Schema::dropIfExists('prbl05_laporan_bulanan');
        Schema::dropIfExists('prbl03_bukti');
        Schema::dropIfExists('prbl03_data');
        Schema::dropIfExists('prbl01_item_realisasi');
        Schema::dropIfExists('prbl01_item_kuisioner');
        Schema::dropIfExists('prbl01_nota_pengeluaran');
        Schema::dropIfExists('prbl01_foto_kegiatan');
        Schema::dropIfExists('prbl01_item_kegiatan');
        Schema::dropIfExists('prbl01_data');
        Schema::dropIfExists('prbl_workflows');
    }
};
