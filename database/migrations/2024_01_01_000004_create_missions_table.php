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
        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('type', ['VOLUNTEER', 'MEDICAL'])->default('VOLUNTEER');
            $table->enum('status', ['DRAFT', 'OPEN', 'CLOSED', 'COMPLETED', 'CANCELED'])->default('DRAFT');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('start_datetime');
            $table->datetime('end_datetime');
            $table->string('location')->nullable();
            $table->text('location_details')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->text('requirements')->nullable(); // JSON array
            $table->text('notes')->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->integer('coverage_percentage')->default(0);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('status');
            $table->index('type');
            $table->index('department_id');
            $table->index('start_datetime');
            $table->index('end_datetime');
            $table->index('is_urgent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
