<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pp_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained();
            $table->jsonb('history')->default('[]');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pp01_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp_workflow_id')->constrained('pp_workflows');
            $table->smallInteger('tahun')->nullable();
            $table->date('tanggal_mulai_pra_raker')->nullable();
            $table->date('tanggal_penetapan_program')->nullable();
            $table->timestamps();
        });

        Schema::create('pp01_kode_bidang_pelayanan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp01_data_id')->constrained('pp01_data')->cascadeOnDelete();
            $table->string('kode', 10);
            $table->string('nama', 255);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['pp01_data_id', 'kode', 'nama']);
        });

        Schema::create('pp01_kode_sub_bidang_pelayanan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp01_data_id')->constrained('pp01_data')->cascadeOnDelete();
            $table->string('kode', 10);
            $table->string('nama', 255);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['pp01_data_id', 'kode', 'nama']);
        });

        Schema::create('pp01_kode_kategori_pelayanan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp01_data_id')->constrained('pp01_data')->cascadeOnDelete();
            $table->string('kode', 10);
            $table->string('nama', 255);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['pp01_data_id', 'kode', 'nama']);
        });

        Schema::create('pp01_kode_jenis_program', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp01_data_id')->constrained('pp01_data')->cascadeOnDelete();
            $table->string('kode', 10);
            $table->string('nama', 255);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['pp01_data_id', 'kode', 'nama']);
        });

        Schema::create('pp02_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp_workflow_id')->constrained('pp_workflows');
            $table->timestamps();
        });

        Schema::create('pp02_item_kuisioner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp02_data_id')->constrained('pp02_data')->cascadeOnDelete();
            $table->string('kode', 10);
            $table->string('pertanyaan', 255);
            $table->string('tipe', 50);
            $table->string('satuan', 100)->nullable();
            $table->timestamps();

            $table->unique(['pp02_data_id', 'kode']);
        });

        Schema::create('pp03_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp_workflow_id')->constrained('pp_workflows');
            $table->timestamps();
        });

        Schema::create('pp03_item_plafon_anggaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp03_data_id')->constrained('pp03_data')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained();
            $table->string('kode_team', 10);
            $table->decimal('plafon_anggaran', 20, 2);
            $table->string('nama_bank', 255);
            $table->string('nama_rekening', 255);
            $table->string('nomor_rekening', 50);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['pp03_data_id', 'team_id']);
            $table->unique(['pp03_data_id', 'kode_team']);
        });

        Schema::create('pp04_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp_workflow_id')->constrained('pp_workflows');
            $table->timestamps();
        });

        Schema::create('pp04_item_dokumen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp04_data_id')->constrained('pp04_data')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files');
            $table->timestamps();
        });

        // PP05 has no data table — approval tracked in history only

        Schema::create('pp06_periode_tahunan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp_workflow_id')->constrained('pp_workflows');
            $table->integer('revision')->default(0);

            // PP01 Author Snapshot
            $table->string('pp01_created_by_user_name');
            $table->string('pp01_created_by_role_name');
            $table->string('pp01_created_by_team_name')->nullable();
            $table->string('pp01_created_by_organization_name');
            $table->string('pp01_created_by_workspace_name');
            $table->timestamp('pp01_created_at');

            // PP02 Author Snapshot
            $table->string('pp02_created_by_user_name');
            $table->string('pp02_created_by_role_name');
            $table->string('pp02_created_by_team_name')->nullable();
            $table->string('pp02_created_by_organization_name');
            $table->string('pp02_created_by_workspace_name');
            $table->timestamp('pp02_created_at');

            // PP03 Author Snapshot
            $table->string('pp03_created_by_user_name');
            $table->string('pp03_created_by_role_name');
            $table->string('pp03_created_by_team_name')->nullable();
            $table->string('pp03_created_by_organization_name');
            $table->string('pp03_created_by_workspace_name');
            $table->timestamp('pp03_created_at');

            // PP04 Author Snapshot
            $table->string('pp04_created_by_user_name');
            $table->string('pp04_created_by_role_name');
            $table->string('pp04_created_by_team_name')->nullable();
            $table->string('pp04_created_by_organization_name');
            $table->string('pp04_created_by_workspace_name');
            $table->timestamp('pp04_created_at');

            // PP05 Author Snapshot
            $table->string('pp05_created_by_user_name');
            $table->string('pp05_created_by_role_name');
            $table->string('pp05_created_by_team_name')->nullable();
            $table->string('pp05_created_by_organization_name');
            $table->string('pp05_created_by_workspace_name');
            $table->timestamp('pp05_created_at');

            // PP01 Data
            $table->smallInteger('tahun');
            $table->date('tanggal_mulai_pra_raker');
            $table->date('tanggal_penetapan_program');

            $table->timestamps();
        });

        Schema::create('pp06_item_plafon_anggaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp06_periode_tahunan_id')->constrained('pp06_periode_tahunan')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained();
            $table->string('kode_team', 10);
            $table->decimal('plafon_anggaran', 20, 2);
            $table->string('nama_bank', 255);
            $table->string('nama_rekening', 255);
            $table->string('nomor_rekening', 50);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['pp06_periode_tahunan_id', 'team_id'], 'pp06_plafon_team_unique');
            $table->unique(['pp06_periode_tahunan_id', 'kode_team'], 'pp06_plafon_kode_unique');
        });

        Schema::create('pp06_kode_bidang_pelayanan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp06_periode_tahunan_id')->constrained('pp06_periode_tahunan')->cascadeOnDelete();
            $table->string('kode', 10);
            $table->string('nama', 255);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['pp06_periode_tahunan_id', 'kode', 'nama'], 'pp06_bidang_unique');
        });

        Schema::create('pp06_kode_sub_bidang_pelayanan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp06_periode_tahunan_id')->constrained('pp06_periode_tahunan')->cascadeOnDelete();
            $table->string('kode', 10);
            $table->string('nama', 255);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['pp06_periode_tahunan_id', 'kode', 'nama'], 'pp06_sub_bidang_unique');
        });

        Schema::create('pp06_kode_kategori_pelayanan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp06_periode_tahunan_id')->constrained('pp06_periode_tahunan')->cascadeOnDelete();
            $table->string('kode', 10);
            $table->string('nama', 255);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['pp06_periode_tahunan_id', 'kode', 'nama'], 'pp06_kategori_unique');
        });

        Schema::create('pp06_kode_jenis_program', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp06_periode_tahunan_id')->constrained('pp06_periode_tahunan')->cascadeOnDelete();
            $table->string('kode', 10);
            $table->string('nama', 255);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['pp06_periode_tahunan_id', 'kode', 'nama'], 'pp06_jenis_unique');
        });

        Schema::create('pp06_item_kuisioner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp06_periode_tahunan_id')->constrained('pp06_periode_tahunan')->cascadeOnDelete();
            $table->string('kode', 10);
            $table->string('pertanyaan', 255);
            $table->string('tipe', 50);
            $table->string('satuan', 100)->nullable();
            $table->timestamps();

            $table->unique(['pp06_periode_tahunan_id', 'kode'], 'pp06_kuisioner_unique');
        });

        Schema::create('pp06_item_dokumen_sop', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp06_periode_tahunan_id')->constrained('pp06_periode_tahunan')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files');
            $table->timestamps();
        });

        Schema::create('pp07_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pp_workflow_id')->constrained('pp_workflows');
            $table->jsonb('draft_data')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pp07_data');
        Schema::dropIfExists('pp06_item_dokumen_sop');
        Schema::dropIfExists('pp06_item_kuisioner');
        Schema::dropIfExists('pp06_kode_jenis_program');
        Schema::dropIfExists('pp06_kode_kategori_pelayanan');
        Schema::dropIfExists('pp06_kode_sub_bidang_pelayanan');
        Schema::dropIfExists('pp06_kode_bidang_pelayanan');
        Schema::dropIfExists('pp06_item_plafon_anggaran');
        Schema::dropIfExists('pp06_periode_tahunan');
        Schema::dropIfExists('pp04_item_dokumen');
        Schema::dropIfExists('pp04_data');
        Schema::dropIfExists('pp03_item_plafon_anggaran');
        Schema::dropIfExists('pp03_data');
        Schema::dropIfExists('pp02_item_kuisioner');
        Schema::dropIfExists('pp02_data');
        Schema::dropIfExists('pp01_kode_jenis_program');
        Schema::dropIfExists('pp01_kode_kategori_pelayanan');
        Schema::dropIfExists('pp01_kode_sub_bidang_pelayanan');
        Schema::dropIfExists('pp01_kode_bidang_pelayanan');
        Schema::dropIfExists('pp01_data');
        Schema::dropIfExists('pp_workflows');
    }
};
