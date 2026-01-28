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
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_id')->constrained('missions')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->datetime('start_time');
            $table->datetime('end_time');
            $table->integer('max_capacity')->default(1);
            $table->integer('current_count')->default(0);
            $table->enum('status', ['OPEN', 'FULL', 'LOCKED', 'CANCELED'])->default('OPEN');
            $table->foreignId('leader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->text('required_skills')->nullable(); // JSON array
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('mission_id');
            $table->index('status');
            $table->index('start_time');
            $table->index('end_time');
            $table->index('leader_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
