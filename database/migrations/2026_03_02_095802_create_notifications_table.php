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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->longText('email_html')->nullable();
            $table->string('link')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'role_id', 'is_read']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
