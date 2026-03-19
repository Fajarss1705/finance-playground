<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pabd_workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('pp_workflow_id')->constrained('pp_workflows');
            $table->smallInteger('bulan_anggaran');
            $table->smallInteger('tahun_anggaran');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('created_by_role_id')->constrained('roles');
            $table->foreignId('created_by_team_id')->constrained('teams');
            $table->foreignId('created_by_org_id')->constrained('organizations');
            $table->jsonb('history')->default('[]');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pabd01_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pabd_workflow_id')->constrained('pabd_workflows');
            $table->boolean('ada_perubahan')->default(false);
            $table->jsonb('pk04_revisions_snapshot')->default('{}');
            $table->timestamps();
        });

        Schema::create('pabd01_item_anggaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pabd01_data_id')->constrained('pabd01_data')->cascadeOnDelete();
            $table->foreignId('pk04_anggaran_id')->constrained('pk04_anggaran');
            $table->boolean('dicairkan')->default(false);
            $table->timestamps();
        });

        Schema::create('pabd02a_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pabd_workflow_id')->constrained('pabd_workflows');
            $table->timestamps();
        });

        Schema::create('pabd02a_item_perubahan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pabd02a_data_id')->constrained('pabd02a_data')->cascadeOnDelete();
            $table->string('tipe_perubahan');
            $table->foreignId('pk04_anggaran_id')->nullable()->constrained('pk04_anggaran');
            $table->smallInteger('bulan_awal')->nullable();
            $table->smallInteger('bulan_tujuan')->nullable();
            $table->foreignId('pk_workflow_id')->nullable()->constrained('pk_workflows');
            $table->text('komentar');
            $table->timestamps();
        });

        Schema::create('pabd02b_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pabd_workflow_id')->constrained('pabd_workflows');
            $table->timestamps();
        });

        Schema::create('pabd02b_item_review', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pabd02b_data_id')->constrained('pabd02b_data')->cascadeOnDelete();
            $table->foreignId('pabd02a_item_perubahan_id')->constrained('pabd02a_item_perubahan');
            $table->text('komentar_approval')->nullable();
            $table->timestamps();
        });

        // PABD03 has no data table — approval/reject tracked in history only

        Schema::create('pabd04_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pabd_workflow_id')->constrained('pabd_workflows');
            $table->timestamps();
        });

        Schema::create('pabd04_item_bukti_transfer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pabd04_data_id')->constrained('pabd04_data')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files');
            $table->timestamps();
        });

        Schema::create('pabd05_pengajuan_bulanan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pabd_workflow_id')->constrained('pabd_workflows');
            $table->string('verification_code', 8)->nullable();

            // PABD01 Author Snapshot
            $table->string('pabd01_created_by_user_name');
            $table->string('pabd01_created_by_role_name');
            $table->string('pabd01_created_by_team_name');
            $table->string('pabd01_created_by_organization_name');
            $table->string('pabd01_created_by_workspace_name');
            $table->timestamp('pabd01_created_at');

            // PABD03 Approver Snapshot
            $table->string('pabd03_approved_by_user_name');
            $table->string('pabd03_approved_by_role_name');
            $table->string('pabd03_approved_by_team_name');
            $table->string('pabd03_approved_by_organization_name');
            $table->string('pabd03_approved_by_workspace_name');
            $table->timestamp('pabd03_approved_at');

            // PABD04 Author Snapshot
            $table->string('pabd04_created_by_user_name');
            $table->string('pabd04_created_by_role_name');
            $table->string('pabd04_created_by_team_name');
            $table->string('pabd04_created_by_organization_name');
            $table->string('pabd04_created_by_workspace_name');
            $table->timestamp('pabd04_created_at');

            // Bank Details (snapshot from PP06)
            $table->string('nama_bank');
            $table->string('nama_rekening');
            $table->string('nomor_rekening', 50);

            // Totals
            $table->decimal('total_anggaran_dicairkan', 20, 2);
            $table->integer('total_item_dicairkan');
            $table->integer('total_item_hangus');

            $table->timestamps();
        });

        Schema::create('pabd05_item_anggaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pabd05_pengajuan_bulanan_id')->constrained('pabd05_pengajuan_bulanan')->cascadeOnDelete();
            $table->foreignId('pk04_anggaran_id')->constrained('pk04_anggaran');
            $table->string('status');
            $table->decimal('nominal_anggaran', 20, 2);
            $table->timestamps();
        });

        Schema::create('pabd05_bukti_transfer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pabd05_pengajuan_bulanan_id')->constrained('pabd05_pengajuan_bulanan')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files');
            $table->timestamps();
        });

        // Add deferred FK constraints from pk04 tables to pabd_workflows
        Schema::table('pk04_kegiatan', function (Blueprint $table) {
            $table->foreign('source_pabd_workflow_id')->references('id')->on('pabd_workflows')->nullOnDelete();
        });

        Schema::table('pk04_anggaran', function (Blueprint $table) {
            $table->foreign('source_pabd_workflow_id')->references('id')->on('pabd_workflows')->nullOnDelete();
            $table->foreign('pencairan_pabd_workflow_id')->references('id')->on('pabd_workflows')->nullOnDelete();
        });

        Schema::table('pk04_anggaran_catatan_perubahan', function (Blueprint $table) {
            $table->foreign('pabd_workflow_id')->references('id')->on('pabd_workflows');
        });
    }

    public function down(): void
    {
        Schema::table('pk04_anggaran_catatan_perubahan', function (Blueprint $table) {
            $table->dropForeign(['pabd_workflow_id']);
        });

        Schema::table('pk04_anggaran', function (Blueprint $table) {
            $table->dropForeign(['pencairan_pabd_workflow_id']);
            $table->dropForeign(['source_pabd_workflow_id']);
        });

        Schema::table('pk04_kegiatan', function (Blueprint $table) {
            $table->dropForeign(['source_pabd_workflow_id']);
        });

        Schema::dropIfExists('pabd05_bukti_transfer');
        Schema::dropIfExists('pabd05_item_anggaran');
        Schema::dropIfExists('pabd05_pengajuan_bulanan');
        Schema::dropIfExists('pabd04_item_bukti_transfer');
        Schema::dropIfExists('pabd04_data');
        Schema::dropIfExists('pabd02b_item_review');
        Schema::dropIfExists('pabd02b_data');
        Schema::dropIfExists('pabd02a_item_perubahan');
        Schema::dropIfExists('pabd02a_data');
        Schema::dropIfExists('pabd01_item_anggaran');
        Schema::dropIfExists('pabd01_data');
        Schema::dropIfExists('pabd_workflows');
    }
};
