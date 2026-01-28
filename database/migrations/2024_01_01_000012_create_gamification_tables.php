<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Πίνακας πόντων
        Schema::create('volunteer_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('points');
            $table->string('reason'); // shift_completed, bonus_weekend, etc.
            $table->string('description')->nullable();
            $table->morphs('pointable'); // participation_request, mission, etc.
            $table->timestamps();
            
            $table->index(['user_id', 'created_at']);
        });

        // Πίνακας διαθέσιμων achievements
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // first_shift, hours_50, etc.
            $table->string('name'); // Πρώτη Βάρδια
            $table->text('description');
            $table->string('icon')->default('bi-award'); // Bootstrap icon
            $table->string('color')->default('primary'); // Badge color
            $table->string('category'); // hours, shifts, special
            $table->integer('threshold')->nullable(); // For milestone achievements
            $table->integer('points_reward')->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Πίνακας achievements εθελοντών
        Schema::create('volunteer_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('achievement_id')->constrained()->onDelete('cascade');
            $table->timestamp('earned_at');
            $table->boolean('notified')->default(false);
            $table->timestamps();
            
            $table->unique(['user_id', 'achievement_id']);
            $table->index('earned_at');
        });

        // Προσθήκη total_points στον πίνακα users
        Schema::table('users', function (Blueprint $table) {
            $table->integer('total_points')->default(0)->after('is_active');
            $table->integer('monthly_points')->default(0)->after('total_points');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['total_points', 'monthly_points']);
        });
        
        Schema::dropIfExists('volunteer_achievements');
        Schema::dropIfExists('achievements');
        Schema::dropIfExists('volunteer_points');
    }
};
