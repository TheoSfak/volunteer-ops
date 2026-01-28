<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Δημιουργία πίνακα email templates.
     */
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // π.χ. 'participation_approved'
            $table->string('name');                     // Ελληνικό όνομα
            $table->string('description')->nullable();  // Περιγραφή χρήσης
            $table->string('subject');                  // Θέμα email
            $table->text('body');                       // HTML body
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
