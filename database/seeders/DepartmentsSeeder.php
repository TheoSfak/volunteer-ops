<?php

namespace Database\Seeders;

use App\Modules\Directory\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentsSeeder extends Seeder
{
    /**
     * Δημιουργία δοκιμαστικών τμημάτων.
     */
    public function run(): void
    {
        // Κεντρική Διοίκηση
        $central = Department::create([
            'name' => 'Κεντρική Διοίκηση',
            'description' => 'Κεντρική διοίκηση και συντονισμός όλων των δράσεων.',
            'is_active' => true,
        ]);

        // Τομέας Υγείας
        $health = Department::create([
            'name' => 'Τομέας Υγείας',
            'description' => 'Εθελοντικές δράσεις στον τομέα της υγείας και πρώτων βοηθειών.',
            'parent_id' => $central->id,
            'is_active' => true,
        ]);

        // Τομέας Περιβάλλοντος
        $environment = Department::create([
            'name' => 'Τομέας Περιβάλλοντος',
            'description' => 'Περιβαλλοντικές δράσεις και καθαρισμοί.',
            'parent_id' => $central->id,
            'is_active' => true,
        ]);

        // Τομέας Κοινωνικής Μέριμνας
        $social = Department::create([
            'name' => 'Τομέας Κοινωνικής Μέριμνας',
            'description' => 'Δράσεις κοινωνικής αλληλεγγύης και στήριξης.',
            'parent_id' => $central->id,
            'is_active' => true,
        ]);

        // Υποτομείς Υγείας
        Department::create([
            'name' => 'Ομάδα Πρώτων Βοηθειών',
            'description' => 'Εξειδικευμένη ομάδα για παροχή πρώτων βοηθειών σε εκδηλώσεις.',
            'parent_id' => $health->id,
            'is_active' => true,
        ]);

        Department::create([
            'name' => 'Ομάδα Αιμοδοσίας',
            'description' => 'Οργάνωση και υποστήριξη εθελοντικών αιμοδοσιών.',
            'parent_id' => $health->id,
            'is_active' => true,
        ]);

        // Υποτομείς Περιβάλλοντος
        Department::create([
            'name' => 'Ομάδα Δασοπροστασίας',
            'description' => 'Εθελοντική δασοπροστασία και δασοπυρόσβεση.',
            'parent_id' => $environment->id,
            'is_active' => true,
        ]);

        Department::create([
            'name' => 'Ομάδα Καθαρισμού',
            'description' => 'Δράσεις καθαρισμού ακτών, δασών και δημόσιων χώρων.',
            'parent_id' => $environment->id,
            'is_active' => true,
        ]);

        // Υποτομείς Κοινωνικής Μέριμνας
        Department::create([
            'name' => 'Ομάδα Διανομής Τροφίμων',
            'description' => 'Διανομή τροφίμων σε ευπαθείς ομάδες.',
            'parent_id' => $social->id,
            'is_active' => true,
        ]);

        Department::create([
            'name' => 'Ομάδα Συνοδείας Ηλικιωμένων',
            'description' => 'Υποστήριξη και συνοδεία ηλικιωμένων.',
            'parent_id' => $social->id,
            'is_active' => true,
        ]);
    }
}
