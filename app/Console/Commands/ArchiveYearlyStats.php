<?php

namespace App\Console\Commands;

use App\Services\StatisticsService;
use Illuminate\Console\Command;

class ArchiveYearlyStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stats:archive-year {year? : Το έτος για αρχειοθέτηση (default: προηγούμενο έτος)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Αρχειοθέτηση ετήσιων στατιστικών εθελοντών';

    /**
     * Execute the console command.
     */
    public function handle(StatisticsService $statisticsService): int
    {
        $year = $this->argument('year') ?? (now()->year - 1);
        
        $this->info("Αρχειοθέτηση στατιστικών για το έτος {$year}...");
        
        $count = $statisticsService->archiveYearlyStats((int) $year);
        
        $this->info("Ολοκληρώθηκε! Αρχειοθετήθηκαν στατιστικά για {$count} εθελοντές.");
        
        return Command::SUCCESS;
    }
}
