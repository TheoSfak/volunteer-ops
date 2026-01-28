<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Shifts\Models\Shift;
use App\Services\GamificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AwardShiftPoints extends Command
{
    protected $signature = 'gamification:award-shift-points';
    protected $description = 'Απονομή πόντων για ολοκληρωμένες βάρδιες';

    protected GamificationService $gamificationService;

    public function __construct(GamificationService $gamificationService)
    {
        parent::__construct();
        $this->gamificationService = $gamificationService;
    }

    public function handle(): int
    {
        $this->info('Έλεγχος ολοκληρωμένων βαρδιών...');

        // Βρες βάρδιες που έχουν τελειώσει και δεν έχουν δοθεί πόντοι
        $completedShifts = Shift::where('end_time', '<', Carbon::now())
            ->whereHas('participationRequests', function ($query) {
                $query->where('status', ParticipationRequest::STATUS_APPROVED)
                    ->where('points_awarded', false);
            })
            ->with(['mission', 'participationRequests' => function ($query) {
                $query->where('status', ParticipationRequest::STATUS_APPROVED)
                    ->where('points_awarded', false)
                    ->with('user');
            }])
            ->get();

        $totalAwarded = 0;

        foreach ($completedShifts as $shift) {
            foreach ($shift->participationRequests as $participation) {
                if (!$participation->user) {
                    continue;
                }

                try {
                    $points = $this->gamificationService->awardPointsForShift(
                        $participation->user,
                        $shift,
                        $participation
                    );

                    // Σημείωσε ότι δόθηκαν πόντοι
                    $participation->update(['points_awarded' => true]);

                    $totalAwarded++;
                    $this->line("  Απονεμήθηκαν {$points} πόντοι στον {$participation->user->name}");
                } catch (\Exception $e) {
                    $this->error("  Σφάλμα για συμμετοχή {$participation->id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Ολοκληρώθηκε! Απονεμήθηκαν πόντοι σε {$totalAwarded} συμμετοχές.");

        return Command::SUCCESS;
    }
}
