<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Directory\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Δημιουργία προεπιλεγμένου διαχειριστή.
     */
    public function run(): void
    {
        $centralDept = Department::where('name', 'Κεντρική Διοίκηση')->first();

        // System Admin
        User::create([
            'name' => 'Διαχειριστής Συστήματος',
            'email' => 'admin@volunteerops.gr',
            'password' => Hash::make('password123'),
            'phone' => '+30 210 1234567',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'department_id' => $centralDept?->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Department Admins
        $healthDept = Department::where('name', 'Τομέας Υγείας')->first();
        User::create([
            'name' => 'Υπεύθυνος Υγείας',
            'email' => 'health@volunteerops.gr',
            'password' => Hash::make('password123'),
            'phone' => '+30 210 2345678',
            'role' => User::ROLE_DEPARTMENT_ADMIN,
            'department_id' => $healthDept?->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $envDept = Department::where('name', 'Τομέας Περιβάλλοντος')->first();
        User::create([
            'name' => 'Υπεύθυνος Περιβάλλοντος',
            'email' => 'environment@volunteerops.gr',
            'password' => Hash::make('password123'),
            'phone' => '+30 210 3456789',
            'role' => User::ROLE_DEPARTMENT_ADMIN,
            'department_id' => $envDept?->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Shift Leaders
        User::create([
            'name' => 'Αρχηγός Ομάδας Α',
            'email' => 'leader1@volunteerops.gr',
            'password' => Hash::make('password123'),
            'phone' => '+30 210 4567890',
            'role' => User::ROLE_SHIFT_LEADER,
            'department_id' => $healthDept?->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Αρχηγός Ομάδας Β',
            'email' => 'leader2@volunteerops.gr',
            'password' => Hash::make('password123'),
            'phone' => '+30 210 5678901',
            'role' => User::ROLE_SHIFT_LEADER,
            'department_id' => $envDept?->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Sample Volunteers
        for ($i = 1; $i <= 10; $i++) {
            $dept = $i <= 5 ? $healthDept : $envDept;
            User::create([
                'name' => "Εθελοντής {$i}",
                'email' => "volunteer{$i}@volunteerops.gr",
                'password' => Hash::make('password123'),
                'phone' => '+30 69' . str_pad($i, 8, '0', STR_PAD_LEFT),
                'role' => User::ROLE_VOLUNTEER,
                'department_id' => $dept?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        }
    }
}
