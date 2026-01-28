<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'description',
        'is_encrypted',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];

    /**
     * Groups διαθέσιμων ρυθμίσεων.
     */
    const GROUP_GENERAL = 'general';
    const GROUP_EMAIL = 'email';
    const GROUP_NOTIFICATIONS = 'notifications';

    const GROUPS = [
        self::GROUP_GENERAL => 'Γενικές Ρυθμίσεις',
        self::GROUP_EMAIL => 'Ρυθμίσεις Email',
        self::GROUP_NOTIFICATIONS => 'Ειδοποιήσεις',
    ];

    /**
     * Λήψη τιμής ρύθμισης.
     */
    public static function get(string $key, $default = null)
    {
        $cacheKey = 'setting_' . $key;
        
        return Cache::remember($cacheKey, 3600, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();
            
            if (!$setting) {
                return $default;
            }
            
            $value = $setting->value;
            
            // Αποκρυπτογράφηση αν χρειάζεται
            if ($setting->is_encrypted && $value) {
                try {
                    $value = Crypt::decryptString($value);
                } catch (\Exception $e) {
                    return $default;
                }
            }
            
            // Μετατροπή τύπου
            return self::castValue($value, $setting->type);
        });
    }

    /**
     * Αποθήκευση τιμής ρύθμισης.
     */
    public static function set(string $key, $value, array $options = []): self
    {
        $setting = self::firstOrNew(['key' => $key]);
        
        $setting->group = $options['group'] ?? $setting->group ?? self::GROUP_GENERAL;
        $setting->type = $options['type'] ?? $setting->type ?? 'string';
        $setting->description = $options['description'] ?? $setting->description;
        $setting->is_encrypted = $options['is_encrypted'] ?? $setting->is_encrypted ?? false;
        
        // Κρυπτογράφηση αν χρειάζεται
        if ($setting->is_encrypted && $value) {
            $setting->value = Crypt::encryptString($value);
        } else {
            $setting->value = is_array($value) ? json_encode($value) : $value;
        }
        
        $setting->save();
        
        // Καθαρισμός cache
        Cache::forget('setting_' . $key);
        Cache::forget('settings_group_' . $setting->group);
        
        return $setting;
    }

    /**
     * Λήψη όλων των ρυθμίσεων ενός group.
     */
    public static function getGroup(string $group): array
    {
        $cacheKey = 'settings_group_' . $group;
        
        return Cache::remember($cacheKey, 3600, function () use ($group) {
            $settings = self::where('group', $group)->get();
            $result = [];
            
            foreach ($settings as $setting) {
                $value = $setting->value;
                
                if ($setting->is_encrypted && $value) {
                    try {
                        $value = Crypt::decryptString($value);
                    } catch (\Exception $e) {
                        $value = null;
                    }
                }
                
                $result[$setting->key] = self::castValue($value, $setting->type);
            }
            
            return $result;
        });
    }

    /**
     * Μετατροπή τιμής σε σωστό τύπο.
     */
    protected static function castValue($value, string $type)
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float' => (float) $value,
            'json', 'array' => is_string($value) ? json_decode($value, true) : $value,
            default => $value,
        };
    }

    /**
     * Email settings keys.
     */
    const EMAIL_KEYS = [
        'mail_mailer' => ['type' => 'string', 'description' => 'Mail Driver (smtp, sendmail, mailgun)', 'default' => 'smtp'],
        'mail_host' => ['type' => 'string', 'description' => 'SMTP Host', 'default' => 'smtp.gmail.com'],
        'mail_port' => ['type' => 'integer', 'description' => 'SMTP Port', 'default' => 587],
        'mail_username' => ['type' => 'string', 'description' => 'SMTP Username', 'default' => ''],
        'mail_password' => ['type' => 'string', 'description' => 'SMTP Password', 'encrypted' => true, 'default' => ''],
        'mail_encryption' => ['type' => 'string', 'description' => 'Encryption (tls, ssl, null)', 'default' => 'tls'],
        'mail_from_address' => ['type' => 'string', 'description' => 'Διεύθυνση Αποστολέα', 'default' => 'noreply@volunteerops.gr'],
        'mail_from_name' => ['type' => 'string', 'description' => 'Όνομα Αποστολέα', 'default' => 'VolunteerOps'],
    ];

    /**
     * Notification settings keys.
     */
    const NOTIFICATION_KEYS = [
        'notify_new_mission' => ['type' => 'boolean', 'description' => 'Ειδοποίηση για νέες αποστολές', 'default' => true],
        'notify_mission_update' => ['type' => 'boolean', 'description' => 'Ειδοποίηση για ενημερώσεις αποστολών', 'default' => true],
        'notify_shift_reminder' => ['type' => 'boolean', 'description' => 'Υπενθύμιση βάρδιας', 'default' => true],
        'notify_shift_reminder_hours' => ['type' => 'integer', 'description' => 'Ώρες πριν την υπενθύμιση', 'default' => 24],
        'notify_participation_approved' => ['type' => 'boolean', 'description' => 'Ειδοποίηση έγκρισης συμμετοχής', 'default' => true],
        'notify_participation_rejected' => ['type' => 'boolean', 'description' => 'Ειδοποίηση απόρριψης συμμετοχής', 'default' => true],
        'notify_new_volunteer' => ['type' => 'boolean', 'description' => 'Ειδοποίηση για νέους εθελοντές (admins)', 'default' => true],
        'notify_email_enabled' => ['type' => 'boolean', 'description' => 'Αποστολή ειδοποιήσεων μέσω email', 'default' => false],
        'notify_inapp_enabled' => ['type' => 'boolean', 'description' => 'Ειδοποιήσεις εντός εφαρμογής', 'default' => true],
    ];

    /**
     * General settings keys.
     */
    const GENERAL_KEYS = [
        'app_name' => ['type' => 'string', 'description' => 'Όνομα Εφαρμογής', 'default' => 'VolunteerOps'],
        'app_timezone' => ['type' => 'string', 'description' => 'Ζώνη Ώρας', 'default' => 'Europe/Athens'],
        'app_date_format' => ['type' => 'string', 'description' => 'Μορφή Ημερομηνίας', 'default' => 'd/m/Y'],
        'app_time_format' => ['type' => 'string', 'description' => 'Μορφή Ώρας', 'default' => 'H:i'],
        'volunteers_require_approval' => ['type' => 'boolean', 'description' => 'Οι εθελοντές απαιτούν έγκριση', 'default' => true],
        'max_shifts_per_volunteer' => ['type' => 'integer', 'description' => 'Μέγιστες βάρδιες ανά εθελοντή/εβδομάδα', 'default' => 5],
        'default_shift_duration' => ['type' => 'integer', 'description' => 'Προεπιλεγμένη διάρκεια βάρδιας (ώρες)', 'default' => 4],
        'organization_name' => ['type' => 'string', 'description' => 'Όνομα Οργανισμού', 'default' => 'Εθελοντική Ομάδα'],
        'organization_phone' => ['type' => 'string', 'description' => 'Τηλέφωνο Επικοινωνίας', 'default' => ''],
        'organization_address' => ['type' => 'string', 'description' => 'Διεύθυνση Οργανισμού', 'default' => ''],
        'maintenance_mode' => ['type' => 'boolean', 'description' => 'Λειτουργία Συντήρησης', 'default' => false],
        'mission_types' => ['type' => 'json', 'description' => 'Τύποι Αποστολών', 'default' => '["Εθελοντική","Υγειονομική"]'],
    ];

    /**
     * Λήψη τύπων αποστολών.
     */
    public static function getMissionTypes(): array
    {
        $types = self::get('mission_types', null);
        
        if ($types === null) {
            return ['Εθελοντική', 'Υγειονομική'];
        }
        
        if (is_string($types)) {
            return json_decode($types, true) ?: ['Εθελοντική', 'Υγειονομική'];
        }
        
        return is_array($types) ? $types : ['Εθελοντική', 'Υγειονομική'];
    }
}
