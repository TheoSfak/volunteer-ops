<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillsSeeder extends Seeder
{
    /**
     * Seed προκαθορισμένων δεξιοτήτων/διπλωμάτων.
     */
    public function run(): void
    {
        $skills = [
            // Διπλώματα οδήγησης
            ['name' => 'Δίπλωμα Μηχανής (Α)', 'category' => 'license', 'icon' => 'bi-bicycle', 'sort_order' => 1],
            ['name' => 'Δίπλωμα Αυτοκινήτου (Β)', 'category' => 'license', 'icon' => 'bi-car-front', 'sort_order' => 2],
            ['name' => 'Δίπλωμα Λεωφορείου (Δ)', 'category' => 'license', 'icon' => 'bi-bus-front', 'sort_order' => 3],
            ['name' => 'Δίπλωμα Φορτηγού (Γ)', 'category' => 'license', 'icon' => 'bi-truck', 'sort_order' => 4],
            
            // Πιστοποιήσεις
            ['name' => 'Πρώτες Βοήθειες', 'category' => 'certification', 'icon' => 'bi-heart-pulse', 'sort_order' => 10],
            ['name' => 'Ναυαγοσώστης', 'category' => 'certification', 'icon' => 'bi-life-preserver', 'sort_order' => 11],
            ['name' => 'Πυροσβέστης', 'category' => 'certification', 'icon' => 'bi-fire', 'sort_order' => 12],
            ['name' => 'Ραδιοερασιτέχνης', 'category' => 'certification', 'icon' => 'bi-broadcast', 'sort_order' => 13],
            
            // Γλώσσες
            ['name' => 'Αγγλικά', 'category' => 'language', 'icon' => 'bi-translate', 'sort_order' => 20],
            ['name' => 'Γαλλικά', 'category' => 'language', 'icon' => 'bi-translate', 'sort_order' => 21],
            ['name' => 'Γερμανικά', 'category' => 'language', 'icon' => 'bi-translate', 'sort_order' => 22],
            ['name' => 'Ιταλικά', 'category' => 'language', 'icon' => 'bi-translate', 'sort_order' => 23],
            
            // Άλλα
            ['name' => 'Νοηματική Γλώσσα', 'category' => 'other', 'icon' => 'bi-hand-index', 'sort_order' => 30],
            ['name' => 'Ιατρικές Γνώσεις', 'category' => 'other', 'icon' => 'bi-hospital', 'sort_order' => 31],
        ];

        foreach ($skills as $skill) {
            Skill::updateOrCreate(
                ['name' => $skill['name']],
                $skill
            );
        }
    }
}
