<?php

namespace App\Modules\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    /**
     * Πίνακας βάσης δεδομένων.
     */
    protected $table = 'audit_logs';

    /**
     * Απενεργοποίηση updated_at.
     */
    const UPDATED_AT = null;

    /**
     * Πεδία που επιτρέπεται η μαζική ανάθεση.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'description',
    ];

    /**
     * Μετατροπές τύπων.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Ελληνικές ονομασίες ενεργειών.
     */
    const ACTION_LABELS = [
        // Auth
        'ΕΓΓΡΑΦΗ_ΧΡΗΣΤΗ' => 'Εγγραφή Χρήστη',
        'ΣΥΝΔΕΣΗ_ΧΡΗΣΤΗ' => 'Σύνδεση Χρήστη',
        'ΑΠΟΣΥΝΔΕΣΗ_ΧΡΗΣΤΗ' => 'Αποσύνδεση Χρήστη',
        'ΕΝΗΜΕΡΩΣΗ_ΠΡΟΦΙΛ' => 'Ενημέρωση Προφίλ',
        'ΑΛΛΑΓΗ_ΚΩΔΙΚΟΥ' => 'Αλλαγή Κωδικού',
        
        // Τμήματα
        'ΔΗΜΙΟΥΡΓΙΑ_ΤΜΗΜΑΤΟΣ' => 'Δημιουργία Τμήματος',
        'ΕΝΗΜΕΡΩΣΗ_ΤΜΗΜΑΤΟΣ' => 'Ενημέρωση Τμήματος',
        'ΔΙΑΓΡΑΦΗ_ΤΜΗΜΑΤΟΣ' => 'Διαγραφή Τμήματος',
        
        // Εθελοντές
        'ΕΝΗΜΕΡΩΣΗ_ΕΘΕΛΟΝΤΗ' => 'Ενημέρωση Εθελοντή',
        
        // Αποστολές
        'ΔΗΜΙΟΥΡΓΙΑ_ΑΠΟΣΤΟΛΗΣ' => 'Δημιουργία Αποστολής',
        'ΕΝΗΜΕΡΩΣΗ_ΑΠΟΣΤΟΛΗΣ' => 'Ενημέρωση Αποστολής',
        'ΔΗΜΟΣΙΕΥΣΗ_ΑΠΟΣΤΟΛΗΣ' => 'Δημοσίευση Αποστολής',
        'ΚΛΕΙΣΙΜΟ_ΑΠΟΣΤΟΛΗΣ' => 'Κλείσιμο Αποστολής',
        'ΑΚΥΡΩΣΗ_ΑΠΟΣΤΟΛΗΣ' => 'Ακύρωση Αποστολής',
        
        // Βάρδιες
        'ΔΗΜΙΟΥΡΓΙΑ_ΒΑΡΔΙΑΣ' => 'Δημιουργία Βάρδιας',
        'ΕΝΗΜΕΡΩΣΗ_ΒΑΡΔΙΑΣ' => 'Ενημέρωση Βάρδιας',
        'ΚΛΕΙΔΩΜΑ_ΒΑΡΔΙΑΣ' => 'Κλείδωμα Βάρδιας',
        
        // Συμμετοχές
        'ΑΙΤΗΣΗ_ΣΥΜΜΕΤΟΧΗΣ' => 'Αίτηση Συμμετοχής',
        'ΕΓΚΡΙΣΗ_ΣΥΜΜΕΤΟΧΗΣ' => 'Έγκριση Συμμετοχής',
        'ΑΠΟΡΡΙΨΗ_ΣΥΜΜΕΤΟΧΗΣ' => 'Απόρριψη Συμμετοχής',
        'ΑΚΥΡΩΣΗ_ΣΥΜΜΕΤΟΧΗΣ' => 'Ακύρωση Συμμετοχής',
        
        // Έγγραφα
        'ΜΕΤΑΦΟΡΤΩΣΗ_ΑΡΧΕΙΟΥ' => 'Μεταφόρτωση Αρχείου',
        'ΔΙΑΓΡΑΦΗ_ΑΡΧΕΙΟΥ' => 'Διαγραφή Αρχείου',
        'ΔΗΜΙΟΥΡΓΙΑ_ΕΓΓΡΑΦΟΥ' => 'Δημιουργία Εγγράφου',
        'ΔΙΑΓΡΑΦΗ_ΕΓΓΡΑΦΟΥ' => 'Διαγραφή Εγγράφου',
    ];

    /**
     * Σχέση με χρήστη.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Σχέση με χρήστη (actor) - alias.
     */
    public function actor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Ελληνική ονομασία ενέργειας.
     */
    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    /**
     * Scope για φιλτράρισμα ανά ενέργεια.
     */
    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope για φιλτράρισμα ανά τύπο οντότητας.
     */
    public function scopeForEntity($query, string $entityType, ?int $entityId = null)
    {
        $query->where('entity_type', $entityType);
        
        if ($entityId) {
            $query->where('entity_id', $entityId);
        }
        
        return $query;
    }

    /**
     * Scope για φιλτράρισμα ανά χρήστη.
     */
    public function scopeByActor($query, int $actorId)
    {
        return $query->where('user_id', $actorId);
    }
}
