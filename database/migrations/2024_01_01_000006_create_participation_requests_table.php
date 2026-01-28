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
        Schema::create('participation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('volunteer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->enum('status', [
                'PENDING',
                'APPROVED',
                'REJECTED',
                'CANCELED_BY_USER',
                'CANCELED_BY_ADMIN'
            ])->default('PENDING');
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('decided_at')->nullable();
            $table->timestamps();
            
            $table->unique(['volunteer_id', 'shift_id']);
            $table->index('volunteer_id');
            $table->index('shift_id');
            $table->index('status');
            $table->index('decided_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('participation_requests');
    }
};
