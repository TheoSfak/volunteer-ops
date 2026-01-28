<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Δημιουργία πινάκων για δεξιότητες/διπλώματα εθελοντών.
     */
    public function up(): void
    {
        // Πίνακας δεξιοτήτων/διπλωμάτων
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // π.χ. "Δίπλωμα Μηχανής"
            $table->string('category');       // π.χ. "license", "language", "certification"
            $table->string('icon')->nullable(); // Bootstrap icon class
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Pivot table: user_skills
        Schema::create('user_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('skill_id')->constrained()->onDelete('cascade');
            $table->string('details')->nullable();    // π.χ. αριθμός διπλώματος, επίπεδο
            $table->date('issued_at')->nullable();    // ημ. έκδοσης
            $table->date('expires_at')->nullable();   // ημ. λήξης (αν υπάρχει)
            $table->timestamps();

            $table->unique(['user_id', 'skill_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_skills');
        Schema::dropIfExists('skills');
    }
};
