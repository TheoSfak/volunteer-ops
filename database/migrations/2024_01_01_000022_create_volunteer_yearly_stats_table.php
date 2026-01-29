<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Πίνακας για αποθήκευση ετήσιων στατιστικών εθελοντών.
     * Στο τέλος κάθε έτους, τα στατιστικά αρχειοθετούνται εδώ.
     */
    public function up(): void
    {
        Schema::create('volunteer_yearly_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->year('year');
            
            // Στατιστικά Συμμετοχής
            $table->integer('total_shifts')->default(0);
            $table->integer('completed_shifts')->default(0);
            $table->integer('no_show_count')->default(0);
            $table->decimal('total_hours', 8, 2)->default(0);
            
            // Πόντοι
            $table->integer('total_points')->default(0);
            
            // Επιτεύγματα που κερδήθηκαν αυτό το έτος
            $table->integer('achievements_earned')->default(0);
            
            // Κατάταξη στο τέλος του έτους
            $table->integer('final_ranking')->nullable();
            $table->integer('total_volunteers_that_year')->nullable();
            
            // Επιπλέον στατιστικά
            $table->integer('weekend_shifts')->default(0);
            $table->integer('night_shifts')->default(0);
            $table->integer('medical_missions')->default(0);
            $table->integer('best_streak')->default(0);
            
            // Αγαπημένο τμήμα
            $table->string('favorite_department')->nullable();
            
            $table->timestamps();
            
            // Ένα record ανά χρήστη ανά έτος
            $table->unique(['user_id', 'year']);
            $table->index('year');
            $table->index('total_points');
            $table->index('total_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('volunteer_yearly_stats');
    }
};
