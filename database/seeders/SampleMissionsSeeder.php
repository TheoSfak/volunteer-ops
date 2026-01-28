<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Directory\Models\Department;
use App\Modules\Missions\Models\Mission;
use App\Modules\Shifts\Models\Shift;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class SampleMissionsSeeder extends Seeder
{
    /**
     * Δημιουργία δοκιμαστικών αποστολών.
     */
    public function run(): void
    {
        $admin = User::where('role', User::ROLE_SYSTEM_ADMIN)->first();
        $healthDept = Department::where('name', 'Τομέας Υγείας')->first();
        $envDept = Department::where('name', 'Τομέας Περιβάλλοντος')->first();
        $leader1 = User::where('email', 'leader1@volunteerops.gr')->first();
        $leader2 = User::where('email', 'leader2@volunteerops.gr')->first();

        // Αποστολή 1: Αιμοδοσία
        $mission1 = Mission::create([
            'title' => 'Εθελοντική Αιμοδοσία - Δημαρχείο',
            'description' => 'Εθελοντική αιμοδοσία στο κεντρικό δημαρχείο σε συνεργασία με το νοσοκομείο.',
            'type' => Mission::TYPE_MEDICAL,
            'status' => Mission::STATUS_OPEN,
            'department_id' => $healthDept?->id,
            'created_by' => $admin?->id,
            'start_datetime' => Carbon::now()->addDays(7)->setTime(9, 0),
            'end_datetime' => Carbon::now()->addDays(7)->setTime(17, 0),
            'location' => 'Κεντρικό Δημαρχείο',
            'location_details' => 'Αίθουσα πολλαπλών χρήσεων, 1ος όροφος',
            'latitude' => 37.9838,
            'longitude' => 23.7275,
            'is_urgent' => false,
        ]);

        // Βάρδιες για Αποστολή 1
        Shift::create([
            'mission_id' => $mission1->id,
            'title' => 'Πρωινή Βάρδια',
            'description' => 'Υποδοχή και καθοδήγηση αιμοδοτών',
            'start_time' => Carbon::now()->addDays(7)->setTime(9, 0),
            'end_time' => Carbon::now()->addDays(7)->setTime(13, 0),
            'max_capacity' => 5,
            'status' => Shift::STATUS_OPEN,
            'leader_id' => $leader1?->id,
            'location' => 'Αίθουσα Α',
        ]);

        Shift::create([
            'mission_id' => $mission1->id,
            'title' => 'Απογευματινή Βάρδια',
            'description' => 'Υποδοχή και καθοδήγηση αιμοδοτών',
            'start_time' => Carbon::now()->addDays(7)->setTime(13, 0),
            'end_time' => Carbon::now()->addDays(7)->setTime(17, 0),
            'max_capacity' => 5,
            'status' => Shift::STATUS_OPEN,
            'leader_id' => $leader1?->id,
            'location' => 'Αίθουσα Α',
        ]);

        // Αποστολή 2: Καθαρισμός Παραλίας
        $mission2 = Mission::create([
            'title' => 'Καθαρισμός Παραλίας Βουλιαγμένης',
            'description' => 'Ομαδικός καθαρισμός της παραλίας από πλαστικά και σκουπίδια.',
            'type' => Mission::TYPE_VOLUNTEER,
            'status' => Mission::STATUS_OPEN,
            'department_id' => $envDept?->id,
            'created_by' => $admin?->id,
            'start_datetime' => Carbon::now()->addDays(14)->setTime(8, 0),
            'end_datetime' => Carbon::now()->addDays(14)->setTime(14, 0),
            'location' => 'Παραλία Βουλιαγμένης',
            'location_details' => 'Συνάντηση στο κεντρικό παρκινγκ',
            'latitude' => 37.8100,
            'longitude' => 23.7800,
            'is_urgent' => false,
        ]);

        // Βάρδιες για Αποστολή 2
        Shift::create([
            'mission_id' => $mission2->id,
            'title' => 'Ομάδα Βόρειας Ακτής',
            'description' => 'Καθαρισμός της βόρειας πλευράς της παραλίας',
            'start_time' => Carbon::now()->addDays(14)->setTime(8, 0),
            'end_time' => Carbon::now()->addDays(14)->setTime(12, 0),
            'max_capacity' => 10,
            'status' => Shift::STATUS_OPEN,
            'leader_id' => $leader2?->id,
            'location' => 'Βόρεια Ακτή',
        ]);

        Shift::create([
            'mission_id' => $mission2->id,
            'title' => 'Ομάδα Νότιας Ακτής',
            'description' => 'Καθαρισμός της νότιας πλευράς της παραλίας',
            'start_time' => Carbon::now()->addDays(14)->setTime(8, 0),
            'end_time' => Carbon::now()->addDays(14)->setTime(12, 0),
            'max_capacity' => 10,
            'status' => Shift::STATUS_OPEN,
            'leader_id' => $leader2?->id,
            'location' => 'Νότια Ακτή',
        ]);

        // Αποστολή 3: Έκτακτη (Draft)
        $mission3 = Mission::create([
            'title' => 'Υποστήριξη Μαραθωνίου Αθήνας',
            'description' => 'Παροχή πρώτων βοηθειών και υποστήριξη δρομέων κατά τον Μαραθώνιο.',
            'type' => Mission::TYPE_MEDICAL,
            'status' => Mission::STATUS_DRAFT,
            'department_id' => $healthDept?->id,
            'created_by' => $admin?->id,
            'start_datetime' => Carbon::now()->addMonths(2)->setTime(6, 0),
            'end_datetime' => Carbon::now()->addMonths(2)->setTime(16, 0),
            'location' => 'Διαδρομή Μαραθωνίου',
            'location_details' => 'Πολλαπλά σημεία κατά τη διαδρομή',
            'is_urgent' => false,
        ]);

        // Αποστολή 4: Urgent
        $mission4 = Mission::create([
            'title' => 'ΕΠΕΙΓΟΝ: Βοήθεια σε πλημμυροπαθείς',
            'description' => 'Άμεση ανάγκη για εθελοντές για διανομή ειδών πρώτης ανάγκης.',
            'type' => Mission::TYPE_VOLUNTEER,
            'status' => Mission::STATUS_OPEN,
            'department_id' => $envDept?->id,
            'created_by' => $admin?->id,
            'start_datetime' => Carbon::now()->addDays(1)->setTime(7, 0),
            'end_datetime' => Carbon::now()->addDays(3)->setTime(20, 0),
            'location' => 'Κέντρο Διανομής - Δήμος Μάνδρας',
            'location_details' => 'Κλειστό Γυμναστήριο',
            'is_urgent' => true,
        ]);

        // Πολλαπλές βάρδιες για επείγουσα αποστολή
        for ($day = 1; $day <= 3; $day++) {
            Shift::create([
                'mission_id' => $mission4->id,
                'title' => "Ημέρα {$day} - Πρωινή",
                'description' => 'Διανομή τροφίμων και νερού',
                'start_time' => Carbon::now()->addDays($day)->setTime(7, 0),
                'end_time' => Carbon::now()->addDays($day)->setTime(14, 0),
                'max_capacity' => 15,
                'status' => Shift::STATUS_OPEN,
                'location' => 'Κλειστό Γυμναστήριο',
            ]);

            Shift::create([
                'mission_id' => $mission4->id,
                'title' => "Ημέρα {$day} - Απογευματινή",
                'description' => 'Διανομή τροφίμων και νερού',
                'start_time' => Carbon::now()->addDays($day)->setTime(14, 0),
                'end_time' => Carbon::now()->addDays($day)->setTime(20, 0),
                'max_capacity' => 15,
                'status' => Shift::STATUS_OPEN,
                'location' => 'Κλειστό Γυμναστήριο',
            ]);
        }
    }
}
