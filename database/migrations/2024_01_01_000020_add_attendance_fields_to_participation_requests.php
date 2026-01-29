<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Προσθήκη πεδίων για καταγραφή παρουσίας και πραγματικών ωρών.
     * Αυτά συμπληρώνονται από τον admin όταν η αποστολή είναι CLOSED.
     */
    public function up(): void
    {
        Schema::table('participation_requests', function (Blueprint $table) {
            // Αν ήρθε ο εθελοντής (default true, false = no-show)
            $table->boolean('attended')->default(true)->after('decided_at');
            
            // Πραγματικές ώρες (υπολογισμένες ή χειροκίνητα)
            $table->decimal('actual_hours', 5, 2)->nullable()->after('attended');
            
            // Πραγματική ώρα έναρξης (αν διαφέρει από τη βάρδια)
            $table->time('actual_start_time')->nullable()->after('actual_hours');
            
            // Πραγματική ώρα λήξης (αν διαφέρει από τη βάρδια)
            $table->time('actual_end_time')->nullable()->after('actual_start_time');
            
            // Σημειώσεις διαχειριστή για τη συμμετοχή
            $table->text('admin_notes')->nullable()->after('actual_end_time');
            
            // Πότε επιβεβαιώθηκε η παρουσία
            $table->datetime('attendance_confirmed_at')->nullable()->after('admin_notes');
            
            // Ποιος επιβεβαίωσε την παρουσία
            $table->foreignId('attendance_confirmed_by')->nullable()->after('attendance_confirmed_at')
                  ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('participation_requests', function (Blueprint $table) {
            $table->dropForeign(['attendance_confirmed_by']);
            $table->dropColumn([
                'attended',
                'actual_hours',
                'actual_start_time',
                'actual_end_time',
                'admin_notes',
                'attendance_confirmed_at',
                'attendance_confirmed_by',
            ]);
        });
    }
};
