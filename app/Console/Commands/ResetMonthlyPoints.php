<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ResetMonthlyPoints extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stats:reset-monthly';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Μηδενισμός μηνιαίων πόντων όλων των εθελοντών (εκτελείται στην αρχή κάθε μήνα)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Μηδενισμός μηνιαίων πόντων...');
        
        $count = User::where('role', User::ROLE_VOLUNTEER)
            ->update(['monthly_points' => 0]);
        
        $this->info("Ολοκληρώθηκε! Μηδενίστηκαν οι μηνιαίοι πόντοι για {$count} εθελοντές.");
        
        return Command::SUCCESS;
    }
}
