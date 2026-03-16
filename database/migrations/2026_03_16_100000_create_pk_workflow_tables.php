<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('pp_workflow_id')->constrained('pp_workflows');
            $table->string('tipe')->default('raker');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('created_by_role_id')->constrained('roles');
            $table->foreignId('created_by_team_id')->constrained('teams');
            $table->foreignId('created_by_org_id')->constrained('organizations');
            $table->jsonb('history')->default('[]');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pk01_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk_workflow_id')->constrained('pk_workflows');
            $table->string('kode_kategori', 10)->nullable();
            $table->string('nama_program', 255)->nullable();
            $table->text('deskripsi_program')->nullable();
            $table->text('tujuan_program')->nullable();
            $table->timestamps();
        });

        Schema::create('pk01_kegiatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk01_data_id')->constrained('pk01_data')->cascadeOnDelete();
            $table->string('nama_kegiatan', 255)->nullable();
            $table->smallInteger('bulan')->nullable();
            $table->timestamps();
        });

        Schema::create('pk01_anggaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk01_kegiatan_id')->constrained('pk01_kegiatan')->cascadeOnDelete();
            $table->string('kode_bidang', 10)->nullable();
            $table->string('kode_sub_bidang', 10)->nullable();
            $table->string('kode_jenis', 10)->nullable();
            $table->string('mata_anggaran', 255)->nullable();
            $table->text('deskripsi_pk')->nullable();
            $table->decimal('nominal_anggaran', 20, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pk01_kuisioner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk01_kegiatan_id')->constrained('pk01_kegiatan')->cascadeOnDelete();
            $table->string('kode_kuisioner', 10)->nullable();
            $table->string('pertanyaan', 255);
            $table->string('tipe', 50);
            $table->string('satuan', 100)->nullable();
            $table->timestamps();
        });

        // PK02A, PK02B, PK03 have no data tables — approval tracked in history only

        Schema::create('pk04_program_tahunan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk_workflow_id')->constrained('pk_workflows');
            $table->integer('revision')->default(0);

            // PK01 Author Snapshot
            $table->string('pk01_created_by_user_name');
            $table->string('pk01_created_by_role_name');
            $table->string('pk01_created_by_team_name');
            $table->string('pk01_created_by_organization_name');
            $table->string('pk01_created_by_workspace_name');
            $table->timestamp('pk01_created_at');

            // Program Data (from PK01)
            $table->string('kode_kategori', 10);
            $table->string('nama_program', 255);
            $table->text('deskripsi_program');
            $table->text('tujuan_program');
            $table->integer('nomer_program');

            // Verification
            $table->string('verification_code', 8)->nullable();

            $table->timestamps();
        });

        Schema::create('pk04_kegiatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk04_program_tahunan_id')->constrained('pk04_program_tahunan')->cascadeOnDelete();
            $table->string('nama_kegiatan', 255);
            $table->smallInteger('bulan');
            $table->integer('nomer_kegiatan');
            $table->string('source')->default('pk');
            // FK to pabd_workflows deferred — table doesn't exist yet
            $table->unsignedBigInteger('source_pabd_workflow_id')->nullable();
            $table->unsignedBigInteger('previous_kegiatan_id')->nullable();
            $table->timestamps();

            $table->foreign('previous_kegiatan_id')->references('id')->on('pk04_kegiatan')->nullOnDelete();
        });

        Schema::create('pk04_anggaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk04_kegiatan_id')->constrained('pk04_kegiatan')->cascadeOnDelete();
            $table->string('kode_bidang', 10);
            $table->string('kode_sub_bidang', 10);
            $table->string('kode_jenis', 10);
            $table->string('mata_anggaran', 255);
            $table->text('deskripsi_pk');
            $table->decimal('nominal_anggaran', 20, 2);
            $table->integer('nomer_anggaran');
            $table->integer('revisi_terakhir')->default(0);
            $table->string('kode_anggaran_baru', 50)->nullable();
            $table->string('kode_anggaran_lama', 30)->nullable();

            // Source tracking (PK or PABD)
            $table->string('status_item')->default('active');
            $table->unsignedBigInteger('previous_anggaran_id')->nullable();
            $table->string('source')->default('pk');
            // FK to pabd_workflows deferred — table doesn't exist yet
            $table->unsignedBigInteger('source_pabd_workflow_id')->nullable();

            // PABD05 side effect (pencairan)
            $table->string('status_pencairan')->nullable();
            $table->date('tanggal_pencairan')->nullable();
            // FK to pabd_workflows deferred
            $table->unsignedBigInteger('pencairan_pabd_workflow_id')->nullable();

            // PRBL05 side effect (realisasi)
            $table->decimal('nominal_realisasi', 20, 2)->nullable();
            $table->string('status_realisasi')->nullable();
            // FK to prbl_workflows deferred
            $table->unsignedBigInteger('prbl_workflow_id')->nullable();

            $table->timestamps();

            $table->foreign('previous_anggaran_id')->references('id')->on('pk04_anggaran')->nullOnDelete();
        });

        Schema::create('pk04_anggaran_catatan_perubahan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk04_anggaran_id')->constrained('pk04_anggaran')->cascadeOnDelete();
            // FK to pabd_workflows deferred — table doesn't exist yet
            $table->unsignedBigInteger('pabd_workflow_id');
            $table->string('tipe_perubahan');
            $table->text('catatan_pemohon');
            $table->text('catatan_approval')->nullable();
            $table->timestamps();
        });

        Schema::create('pk04_kuisioner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk04_kegiatan_id')->constrained('pk04_kegiatan')->cascadeOnDelete();
            $table->string('kode_kuisioner', 10)->nullable();
            $table->string('pertanyaan', 255);
            $table->string('tipe', 50);
            $table->string('satuan', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('pk05_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk_workflow_id')->constrained('pk_workflows');
            $table->jsonb('draft_data')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk05_data');
        Schema::dropIfExists('pk04_kuisioner');
        Schema::dropIfExists('pk04_anggaran_catatan_perubahan');
        Schema::dropIfExists('pk04_anggaran');
        Schema::dropIfExists('pk04_kegiatan');
        Schema::dropIfExists('pk04_program_tahunan');
        Schema::dropIfExists('pk01_kuisioner');
        Schema::dropIfExists('pk01_anggaran');
        Schema::dropIfExists('pk01_kegiatan');
        Schema::dropIfExists('pk01_data');
        Schema::dropIfExists('pk_workflows');
    }
};
