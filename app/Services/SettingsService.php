<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\EmailTemplate;
use App\Modules\Directory\Models\Department;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SettingsService
{
    /**
     * Λήψη email settings.
     */
    public function getEmailSettings(): array
    {
        $settings = [];
        
        foreach (Setting::EMAIL_KEYS as $key => $config) {
            $settings[$key] = Setting::get($key, $config['default'] ?? '');
        }
        
        return $settings;
    }

    /**
     * Αποθήκευση email settings.
     */
    public function updateEmailSettings(array $data): void
    {
        foreach ($data as $key => $value) {
            $isEncrypted = $key === 'mail_password';
            
            // Αν το password είναι κενό, μην το ενημερώνεις
            if ($key === 'mail_password' && empty($value)) {
                continue;
            }
            
            Setting::set($key, $value, [
                'group' => Setting::GROUP_EMAIL,
                'type' => $key === 'mail_port' ? 'integer' : 'string',
                'is_encrypted' => $isEncrypted,
            ]);
        }
    }

    /**
     * Λήψη notification settings.
     */
    public function getNotificationSettings(): array
    {
        $settings = [];
        
        foreach (Setting::NOTIFICATION_KEYS as $key => $config) {
            $settings[$key] = Setting::get($key, $config['default'] ?? null);
        }
        
        return $settings;
    }

    /**
     * Αποθήκευση notification settings.
     */
    public function updateNotificationSettings(array $data): void
    {
        $booleanFields = [
            'notify_new_mission', 'notify_mission_update', 'notify_shift_reminder',
            'notify_participation_approved', 'notify_participation_rejected',
            'notify_new_volunteer', 'notify_email_enabled', 'notify_inapp_enabled',
        ];
        
        foreach ($data as $key => $value) {
            if (array_key_exists($key, Setting::NOTIFICATION_KEYS)) {
                $type = in_array($key, $booleanFields) ? 'boolean' : 
                        ($key === 'notify_shift_reminder_hours' ? 'integer' : 'string');
                
                Setting::set($key, $value, [
                    'group' => Setting::GROUP_NOTIFICATIONS,
                    'type' => $type,
                ]);
            }
        }
    }

    /**
     * Λήψη general settings.
     */
    public function getGeneralSettings(): array
    {
        $settings = [];
        
        foreach (Setting::GENERAL_KEYS as $key => $config) {
            $settings[$key] = Setting::get($key, $config['default'] ?? null);
        }
        
        return $settings;
    }

    /**
     * Αποθήκευση general settings.
     */
    public function updateGeneralSettings(array $data): void
    {
        $booleanFields = ['volunteers_require_approval', 'maintenance_mode'];
        $integerFields = ['max_shifts_per_volunteer', 'default_shift_duration'];
        
        foreach ($data as $key => $value) {
            if (array_key_exists($key, Setting::GENERAL_KEYS)) {
                $type = 'string';
                if (in_array($key, $booleanFields)) {
                    $type = 'boolean';
                } elseif (in_array($key, $integerFields)) {
                    $type = 'integer';
                }
                
                Setting::set($key, $value, [
                    'group' => Setting::GROUP_GENERAL,
                    'type' => $type,
                ]);
            }
        }
    }

    /**
     * Ρύθμιση mailer runtime με αποθηκευμένες ρυθμίσεις.
     */
    public function configureMailer(): void
    {
        $emailSettings = $this->getEmailSettings();
        
        Config::set([
            'mail.default' => $emailSettings['mail_mailer'] ?? 'smtp',
            'mail.mailers.smtp.host' => $emailSettings['mail_host'],
            'mail.mailers.smtp.port' => $emailSettings['mail_port'],
            'mail.mailers.smtp.username' => $emailSettings['mail_username'],
            'mail.mailers.smtp.password' => $emailSettings['mail_password'],
            'mail.mailers.smtp.encryption' => $emailSettings['mail_encryption'],
            'mail.from.address' => $emailSettings['mail_from_address'],
            'mail.from.name' => $emailSettings['mail_from_name'],
        ]);
    }

    /**
     * Αποστολή δοκιμαστικού email.
     */
    public function sendTestEmail(string $toEmail): bool
    {
        $this->configureMailer();
        
        Mail::raw('Αυτό είναι ένα δοκιμαστικό email από το VolunteerOps.', function ($message) use ($toEmail) {
            $message->to($toEmail)
                ->subject('Δοκιμαστικό Email - VolunteerOps');
        });
        
        return true;
    }

    /**
     * Λήψη όλων των δεδομένων για τη σελίδα ρυθμίσεων.
     */
    public function getSettingsPageData(): array
    {
        return [
            'emailSettings' => $this->getEmailSettings(),
            'notificationSettings' => $this->getNotificationSettings(),
            'generalSettings' => $this->getGeneralSettings(),
            'missionTypes' => Setting::getMissionTypes(),
            'departments' => Department::withCount('users')->orderBy('name')->get(),
            'emailTemplates' => EmailTemplate::orderBy('name')->get(),
            'emailLogo' => Setting::get('email_logo'),
        ];
    }

    /**
     * Προσθήκη τύπου αποστολής.
     */
    public function addMissionType(string $type): array
    {
        $types = Setting::getMissionTypes();
        
        if (in_array($type, $types)) {
            return ['success' => false, 'message' => 'Ο τύπος υπάρχει ήδη.'];
        }
        
        $types[] = $type;
        Setting::set('mission_types', $types, [
            'group' => Setting::GROUP_GENERAL,
            'type' => 'json',
        ]);
        
        return ['success' => true, 'message' => 'Ο τύπος προστέθηκε επιτυχώς.'];
    }

    /**
     * Αφαίρεση τύπου αποστολής.
     */
    public function removeMissionType(string $type): array
    {
        $types = Setting::getMissionTypes();
        $types = array_values(array_filter($types, fn($t) => $t !== $type));
        
        if (count($types) === 0) {
            return ['success' => false, 'message' => 'Πρέπει να υπάρχει τουλάχιστον ένας τύπος.'];
        }
        
        Setting::set('mission_types', $types, [
            'group' => Setting::GROUP_GENERAL,
            'type' => 'json',
        ]);
        
        return ['success' => true, 'message' => 'Ο τύπος αφαιρέθηκε επιτυχώς.'];
    }
}
