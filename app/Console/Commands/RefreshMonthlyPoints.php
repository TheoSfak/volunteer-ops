<?php

namespace App\Console\Commands;

use App\Services\GamificationService;
use Illuminate\Console\Command;

class RefreshMonthlyPoints extends Command
{
    protected $signature = 'gamification:refresh-monthly';
    protected $description = 'Ανανέωση μηνιαίων πόντων για όλους τους εθελοντές';

    protected GamificationService $gamificationService;

    public function __construct(GamificationService $gamificationService)
    {
        parent::__construct();
        $this->gamificationService = $gamificationService;
    }

    public function handle(): int
    {
        $this->info('Ανανέωση μηνιαίων πόντων...');
        
        $this->gamificationService->refreshMonthlyPoints();
        
        $this->info('Ολοκληρώθηκε!');
        
        return Command::SUCCESS;
    }
}
