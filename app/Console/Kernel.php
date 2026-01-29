<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Ορισμός scheduled tasks.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Καθαρισμός ληγμένων tokens κάθε μέρα
        $schedule->command('sanctum:prune-expired --hours=24')->daily();
        
        // Μηδενισμός μηνιαίων πόντων - 1η κάθε μήνα στα μεσάνυχτα
        $schedule->command('stats:reset-monthly')->monthlyOn(1, '00:01');
        
        // Αρχειοθέτηση & μηδενισμός ετήσιων στατιστικών - 1η Ιανουαρίου
        $schedule->command('stats:archive-year')
            ->yearlyOn(1, 1, '00:05'); // 1η Ιαν, 00:05
        $schedule->command('stats:reset-yearly --force')
            ->yearlyOn(1, 1, '00:10'); // 1η Ιαν, 00:10
    }

    /**
     * Φόρτωση console commands.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
