<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Προσθήκη πεδίου για τον λόγο ακύρωσης αποστολής.
     */
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            // Λόγος ακύρωσης (συμπληρώνεται όταν η αποστολή ακυρώνεται)
            $table->text('cancellation_reason')->nullable()->after('notes');
            
            // Ποιος ακύρωσε την αποστολή
            $table->foreignId('canceled_by')->nullable()->after('cancellation_reason')
                  ->constrained('users')->nullOnDelete();
            
            // Πότε ακυρώθηκε
            $table->datetime('canceled_at')->nullable()->after('canceled_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropForeign(['canceled_by']);
            $table->dropColumn([
                'cancellation_reason',
                'canceled_by',
                'canceled_at',
            ]);
        });
    }
};
