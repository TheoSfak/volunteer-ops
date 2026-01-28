<?php

namespace Database\Seeders;

use App\Models\Achievement;
use Illuminate\Database\Seeder;

class AchievementsSeeder extends Seeder
{
    public function run(): void
    {
        $achievements = [
            // Ώρες Εθελοντισμού
            [
                'code' => Achievement::CODE_FIRST_SHIFT,
                'name' => 'Πρώτη Βάρδια',
                'description' => 'Ολοκλήρωσες την πρώτη σου βάρδια!',
                'icon' => 'fas fa-star',
                'color' => 'warning',
                'category' => Achievement::CATEGORY_MILESTONE,
                'threshold' => 1,
                'points_reward' => 50,
            ],
            [
                'code' => Achievement::CODE_HOURS_50,
                'name' => '50 Ώρες Εθελοντισμού',
                'description' => 'Συμπλήρωσες 50 ώρες εθελοντικής προσφοράς!',
                'icon' => 'fas fa-clock',
                'color' => 'info',
                'category' => Achievement::CATEGORY_HOURS,
                'threshold' => 50,
                'points_reward' => 100,
            ],
            [
                'code' => Achievement::CODE_HOURS_100,
                'name' => '100 Ώρες Εθελοντισμού',
                'description' => 'Συμπλήρωσες 100 ώρες εθελοντικής προσφοράς! Είσαι αστέρι!',
                'icon' => 'fas fa-medal',
                'color' => 'primary',
                'category' => Achievement::CATEGORY_HOURS,
                'threshold' => 100,
                'points_reward' => 250,
            ],
            [
                'code' => Achievement::CODE_HOURS_250,
                'name' => '250 Ώρες Εθελοντισμού',
                'description' => 'Συμπλήρωσες 250 ώρες εθελοντικής προσφοράς! Αξιοθαύμαστη προσφορά!',
                'icon' => 'fas fa-trophy',
                'color' => 'success',
                'category' => Achievement::CATEGORY_HOURS,
                'threshold' => 250,
                'points_reward' => 500,
            ],
            [
                'code' => Achievement::CODE_HOURS_500,
                'name' => '500 Ώρες Εθελοντισμού',
                'description' => 'Συμπλήρωσες 500 ώρες εθελοντικής προσφοράς! Θρύλος του εθελοντισμού!',
                'icon' => 'fas fa-crown',
                'color' => 'warning',
                'category' => Achievement::CATEGORY_HOURS,
                'threshold' => 500,
                'points_reward' => 1000,
            ],
            [
                'code' => Achievement::CODE_HOURS_1000,
                'name' => '1000 Ώρες Εθελοντισμού',
                'description' => 'Συμπλήρωσες 1000 ώρες! Είσαι ήρωας της κοινότητας!',
                'icon' => 'fas fa-gem',
                'color' => 'danger',
                'category' => Achievement::CATEGORY_HOURS,
                'threshold' => 1000,
                'points_reward' => 2500,
            ],

            // Βάρδιες
            [
                'code' => Achievement::CODE_SHIFTS_10,
                'name' => '10 Βάρδιες',
                'description' => 'Ολοκλήρωσες 10 βάρδιες!',
                'icon' => 'fas fa-calendar-check',
                'color' => 'info',
                'category' => Achievement::CATEGORY_SHIFTS,
                'threshold' => 10,
                'points_reward' => 75,
            ],
            [
                'code' => Achievement::CODE_SHIFTS_25,
                'name' => '25 Βάρδιες',
                'description' => 'Ολοκλήρωσες 25 βάρδιες!',
                'icon' => 'fas fa-calendar-alt',
                'color' => 'primary',
                'category' => Achievement::CATEGORY_SHIFTS,
                'threshold' => 25,
                'points_reward' => 150,
            ],
            [
                'code' => Achievement::CODE_SHIFTS_50,
                'name' => '50 Βάρδιες',
                'description' => 'Ολοκλήρωσες 50 βάρδιες!',
                'icon' => 'fas fa-calendar',
                'color' => 'success',
                'category' => Achievement::CATEGORY_SHIFTS,
                'threshold' => 50,
                'points_reward' => 300,
            ],
            [
                'code' => Achievement::CODE_SHIFTS_100,
                'name' => '100 Βάρδιες',
                'description' => 'Ολοκλήρωσες 100 βάρδιες! Απίστευτη αφοσίωση!',
                'icon' => 'fas fa-calendar-star',
                'color' => 'warning',
                'category' => Achievement::CATEGORY_SHIFTS,
                'threshold' => 100,
                'points_reward' => 750,
            ],

            // Συνέπεια (χωρίς ακυρώσεις)
            [
                'code' => Achievement::CODE_RELIABLE_10,
                'name' => 'Αξιόπιστος',
                'description' => '10 βάρδιες χωρίς καμία ακύρωση!',
                'icon' => 'fas fa-handshake',
                'color' => 'info',
                'category' => Achievement::CATEGORY_STREAK,
                'threshold' => 10,
                'points_reward' => 100,
            ],
            [
                'code' => Achievement::CODE_RELIABLE_25,
                'name' => 'Πολύ Αξιόπιστος',
                'description' => '25 βάρδιες χωρίς καμία ακύρωση!',
                'icon' => 'fas fa-shield-alt',
                'color' => 'primary',
                'category' => Achievement::CATEGORY_STREAK,
                'threshold' => 25,
                'points_reward' => 250,
            ],
            [
                'code' => Achievement::CODE_RELIABLE_50,
                'name' => 'Υπέρ-Αξιόπιστος',
                'description' => '50 βάρδιες χωρίς καμία ακύρωση! Μπορούμε πάντα να βασιστούμε σε εσένα!',
                'icon' => 'fas fa-shield-check',
                'color' => 'success',
                'category' => Achievement::CATEGORY_STREAK,
                'threshold' => 50,
                'points_reward' => 500,
            ],

            // Ειδικά
            [
                'code' => Achievement::CODE_WEEKEND_WARRIOR,
                'name' => 'Πολεμιστής Σαββατοκύριακου',
                'description' => 'Ολοκλήρωσες 10 βάρδιες σε Σαββατοκύριακα!',
                'icon' => 'fas fa-sun',
                'color' => 'warning',
                'category' => Achievement::CATEGORY_SPECIAL,
                'threshold' => 10,
                'points_reward' => 150,
            ],
            [
                'code' => Achievement::CODE_NIGHT_OWL,
                'name' => 'Νυχτερινή Κουκουβάγια',
                'description' => 'Ολοκλήρωσες 10 νυχτερινές βάρδιες!',
                'icon' => 'fas fa-moon',
                'color' => 'secondary',
                'category' => Achievement::CATEGORY_SPECIAL,
                'threshold' => 10,
                'points_reward' => 150,
            ],
            [
                'code' => Achievement::CODE_MEDICAL_HERO,
                'name' => 'Υγειονομικός Ήρωας',
                'description' => 'Ολοκλήρωσες 10 υγειονομικές αποστολές!',
                'icon' => 'fas fa-heartbeat',
                'color' => 'danger',
                'category' => Achievement::CATEGORY_SPECIAL,
                'threshold' => 10,
                'points_reward' => 200,
            ],
            [
                'code' => Achievement::CODE_EARLY_ADOPTER,
                'name' => 'Πρωτοπόρος',
                'description' => 'Εγγράφηκες στους πρώτους 100 εθελοντές!',
                'icon' => 'fas fa-rocket',
                'color' => 'primary',
                'category' => Achievement::CATEGORY_SPECIAL,
                'threshold' => 100,
                'points_reward' => 100,
            ],
            [
                'code' => Achievement::CODE_TEAM_PLAYER,
                'name' => 'Ομαδικός Παίκτης',
                'description' => 'Συμμετείχες σε 5 αποστολές με 10+ εθελοντές!',
                'icon' => 'fas fa-users',
                'color' => 'success',
                'category' => Achievement::CATEGORY_SPECIAL,
                'threshold' => 5,
                'points_reward' => 100,
            ],
        ];

        foreach ($achievements as $achievement) {
            Achievement::updateOrCreate(
                ['code' => $achievement['code']],
                array_merge($achievement, ['is_active' => true])
            );
        }
    }
}
