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
            $table->unique(['pp_workflow_id', 'revision'], 'pp06_workflow_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pp06_periode_tahunan', function (Blueprint $table) {
            $table->dropUnique('pp06_workflow_revision_unique');
        });
    }
};
