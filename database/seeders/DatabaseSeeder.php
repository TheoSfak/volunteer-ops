<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Εκτέλεση όλων των seeders.
     */
    public function run(): void
    {
        $this->call([
            DepartmentsSeeder::class,
            AdminUserSeeder::class,
            SampleMissionsSeeder::class,
            AchievementsSeeder::class,
        ]);
    }
}
