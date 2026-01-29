<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ResetYearlyPoints extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stats:reset-yearly {--force : Εκτέλεση χωρίς επιβεβαίωση}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Μηδενισμός ετήσιων πόντων όλων των εθελοντών (εκτελείται στην αρχή κάθε έτους)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('ΠΡΟΣΟΧΗ: Αυτή η ενέργεια θα μηδενίσει τους πόντους όλων των εθελοντών. Συνέχεια;')) {
                $this->info('Ακυρώθηκε.');
                return Command::SUCCESS;
            }
        }
        
        $this->info('Μηδενισμός ετήσιων πόντων...');
        
        $count = User::where('role', User::ROLE_VOLUNTEER)
            ->update([
                'total_points' => 0,
                'monthly_points' => 0,
            ]);
        
        $this->info("Ολοκληρώθηκε! Μηδενίστηκαν οι πόντοι για {$count} εθελοντές.");
        
        return Command::SUCCESS;
    }
}
