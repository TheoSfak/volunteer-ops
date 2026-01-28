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
        
        // Ενημέρωση στατιστικών κάλυψης αποστολών
        // $schedule->command('missions:update-coverage')->hourly();
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
