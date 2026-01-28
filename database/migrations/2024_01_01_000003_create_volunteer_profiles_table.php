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
        Schema::create('volunteer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('rank', ['DOKIMOS', 'ENERGOS'])->default('DOKIMOS');
            $table->text('specialties')->nullable(); // JSON array
            $table->text('certifications')->nullable(); // JSON array
            $table->text('emergency_contact')->nullable(); // JSON object
            $table->date('date_of_birth')->nullable();
            $table->string('blood_type')->nullable();
            $table->text('medical_notes')->nullable();
            $table->text('availability')->nullable(); // JSON object
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->timestamps();
            
            $table->unique('user_id');
            $table->index('rank');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('volunteer_profiles');
    }
};
