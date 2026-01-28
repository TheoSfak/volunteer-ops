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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('category', ['GENERAL', 'MISSION', 'CERT'])->default('GENERAL');
            $table->enum('visibility', ['PUBLIC', 'ADMINS', 'PRIVATE'])->default('PUBLIC');
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('mission_id')->nullable()->constrained('missions')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('category');
            $table->index('visibility');
            $table->index('mission_id');
            $table->index('department_id');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
