<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class EmailTemplatesSeeder extends Seeder
{
    /**
     * Seed προκαθορισμένων email templates.
     */
    public function run(): void
    {
        $appName = Setting::get('app_name', 'VolunteerOps');

        $templates = [
            [
                'code' => EmailTemplate::CODE_WELCOME,
                'name' => 'Καλωσόρισμα Νέου Χρήστη',
                'description' => 'Αποστέλλεται κατά την εγγραφή νέου εθελοντή',
                'subject' => 'Καλώς ήρθατε στο {{app_name}}!',
                'body' => $this->getWelcomeTemplate(),
            ],
            [
                'code' => EmailTemplate::CODE_PARTICIPATION_APPROVED,
                'name' => 'Έγκριση Συμμετοχής',
                'description' => 'Αποστέλλεται όταν εγκρίνεται αίτηση συμμετοχής',
                'subject' => 'Η συμμετοχή σας εγκρίθηκε - {{mission_title}}',
                'body' => $this->getParticipationApprovedTemplate(),
            ],
            [
                'code' => EmailTemplate::CODE_PARTICIPATION_REJECTED,
                'name' => 'Απόρριψη Συμμετοχής',
                'description' => 'Αποστέλλεται όταν απορρίπτεται αίτηση συμμετοχής',
                'subject' => 'Ενημέρωση για την αίτησή σας - {{mission_title}}',
                'body' => $this->getParticipationRejectedTemplate(),
            ],
            [
                'code' => EmailTemplate::CODE_SHIFT_REMINDER,
                'name' => 'Υπενθύμιση Βάρδιας',
                'description' => 'Αποστέλλεται πριν από προγραμματισμένη βάρδια',
                'subject' => 'Υπενθύμιση: Βάρδια σε {{hours_until}} ώρες',
                'body' => $this->getShiftReminderTemplate(),
            ],
            [
                'code' => EmailTemplate::CODE_PASSWORD_RESET,
                'name' => 'Επαναφορά Κωδικού',
                'description' => 'Αποστέλλεται όταν ζητηθεί επαναφορά κωδικού',
                'subject' => 'Επαναφορά Κωδικού - {{app_name}}',
                'body' => $this->getPasswordResetTemplate(),
            ],
            [
                'code' => EmailTemplate::CODE_NEW_MISSION,
                'name' => 'Νέα Αποστολή',
                'description' => 'Αποστέλλεται όταν δημιουργείται νέα αποστολή',
                'subject' => 'Νέα Αποστολή: {{mission_title}}',
                'body' => $this->getNewMissionTemplate(),
            ],
        ];

        foreach ($templates as $template) {
            EmailTemplate::updateOrCreate(
                ['code' => $template['code']],
                $template
            );
        }
    }

    private function getWelcomeTemplate(): string
    {
        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="color: #4f46e5;">{{app_name}}</h1>
    </div>
    
    <h2 style="color: #333;">Καλώς ήρθατε, {{volunteer_name}}!</h2>
    
    <p style="color: #666; line-height: 1.6;">
        Σας ευχαριστούμε που εγγραφήκατε στο {{app_name}}. 
        Είμαστε ενθουσιασμένοι που θα σας έχουμε στην ομάδα μας!
    </p>
    
    <p style="color: #666; line-height: 1.6;">
        Μπορείτε τώρα να συνδεθείτε και να δείτε τις διαθέσιμες αποστολές.
    </p>
    
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{login_url}}" style="background-color: #4f46e5; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
            Σύνδεση
        </a>
    </div>
    
    <p style="color: #999; font-size: 12px; margin-top: 40px; text-align: center;">
        Αυτό το email στάλθηκε από το {{app_name}}
    </p>
</div>
HTML;
    }

    private function getParticipationApprovedTemplate(): string
    {
        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="color: #4f46e5;">{{app_name}}</h1>
    </div>
    
    <div style="background-color: #d1fae5; border-left: 4px solid #10b981; padding: 15px; margin-bottom: 20px;">
        <strong style="color: #065f46;">✓ Η συμμετοχή σας εγκρίθηκε!</strong>
    </div>
    
    <p style="color: #666;">Αγαπητέ/ή {{volunteer_name}},</p>
    
    <p style="color: #666; line-height: 1.6;">
        Η αίτησή σας για συμμετοχή στην αποστολή <strong>{{mission_title}}</strong> εγκρίθηκε.
    </p>
    
    <div style="background-color: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;">
        <h3 style="margin-top: 0; color: #333;">Λεπτομέρειες Βάρδιας</h3>
        <p style="margin: 5px 0;"><strong>📅 Ημερομηνία:</strong> {{shift_date}}</p>
        <p style="margin: 5px 0;"><strong>🕐 Ώρα:</strong> {{shift_time}}</p>
        <p style="margin: 5px 0;"><strong>📍 Τοποθεσία:</strong> {{location}}</p>
    </div>
    
    <p style="color: #666; line-height: 1.6;">
        Παρακαλούμε να είστε στο σημείο συνάντησης εγκαίρως.
    </p>
    
    <p style="color: #999; font-size: 12px; margin-top: 40px; text-align: center;">
        Αυτό το email στάλθηκε από το {{app_name}}
    </p>
</div>
HTML;
    }

    private function getParticipationRejectedTemplate(): string
    {
        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="color: #4f46e5;">{{app_name}}</h1>
    </div>
    
    <p style="color: #666;">Αγαπητέ/ή {{volunteer_name}},</p>
    
    <p style="color: #666; line-height: 1.6;">
        Δυστυχώς, η αίτησή σας για συμμετοχή στην αποστολή <strong>{{mission_title}}</strong> 
        στις {{shift_date}} δεν μπόρεσε να εγκριθεί.
    </p>
    
    <div style="background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0;">
        <strong>Λόγος:</strong> {{rejection_reason}}
    </div>
    
    <p style="color: #666; line-height: 1.6;">
        Σας ενθαρρύνουμε να δηλώσετε συμμετοχή σε άλλες διαθέσιμες βάρδιες.
    </p>
    
    <p style="color: #999; font-size: 12px; margin-top: 40px; text-align: center;">
        Αυτό το email στάλθηκε από το {{app_name}}
    </p>
</div>
HTML;
    }

    private function getShiftReminderTemplate(): string
    {
        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="color: #4f46e5;">{{app_name}}</h1>
    </div>
    
    <div style="background-color: #e0f2fe; border-left: 4px solid #0ea5e9; padding: 15px; margin-bottom: 20px;">
        <strong style="color: #0369a1;">🔔 Υπενθύμιση Βάρδιας</strong>
    </div>
    
    <p style="color: #666;">Αγαπητέ/ή {{volunteer_name}},</p>
    
    <p style="color: #666; line-height: 1.6;">
        Σας υπενθυμίζουμε ότι έχετε βάρδια σε <strong>{{hours_until}} ώρες</strong>.
    </p>
    
    <div style="background-color: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;">
        <h3 style="margin-top: 0; color: #333;">{{mission_title}}</h3>
        <p style="margin: 5px 0;"><strong>📅 Ημερομηνία:</strong> {{shift_date}}</p>
        <p style="margin: 5px 0;"><strong>🕐 Ώρα:</strong> {{shift_time}}</p>
        <p style="margin: 5px 0;"><strong>📍 Τοποθεσία:</strong> {{location}}</p>
    </div>
    
    <p style="color: #999; font-size: 12px; margin-top: 40px; text-align: center;">
        Αυτό το email στάλθηκε από το {{app_name}}
    </p>
</div>
HTML;
    }

    private function getPasswordResetTemplate(): string
    {
        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="color: #4f46e5;">{{app_name}}</h1>
    </div>
    
    <p style="color: #666;">Αγαπητέ/ή {{volunteer_name}},</p>
    
    <p style="color: #666; line-height: 1.6;">
        Λάβαμε αίτημα για επαναφορά του κωδικού πρόσβασής σας.
    </p>
    
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{reset_url}}" style="background-color: #4f46e5; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
            Επαναφορά Κωδικού
        </a>
    </div>
    
    <p style="color: #999; font-size: 14px;">
        Ο σύνδεσμος ισχύει για {{expiry_minutes}} λεπτά.
    </p>
    
    <p style="color: #666; line-height: 1.6;">
        Αν δεν ζητήσατε εσείς την επαναφορά, αγνοήστε αυτό το email.
    </p>
    
    <p style="color: #999; font-size: 12px; margin-top: 40px; text-align: center;">
        Αυτό το email στάλθηκε από το {{app_name}}
    </p>
</div>
HTML;
    }

    private function getNewMissionTemplate(): string
    {
        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="color: #4f46e5;">{{app_name}}</h1>
    </div>
    
    <div style="background-color: #ede9fe; border-left: 4px solid #8b5cf6; padding: 15px; margin-bottom: 20px;">
        <strong style="color: #5b21b6;">🚀 Νέα Αποστολή Διαθέσιμη!</strong>
    </div>
    
    <p style="color: #666;">Αγαπητέ/ή {{volunteer_name}},</p>
    
    <p style="color: #666; line-height: 1.6;">
        Μια νέα αποστολή είναι τώρα διαθέσιμη και περιμένει τη συμμετοχή σας!
    </p>
    
    <div style="background-color: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;">
        <h3 style="margin-top: 0; color: #333;">{{mission_title}}</h3>
        <p style="color: #666; line-height: 1.6;">{{mission_description}}</p>
        <p style="margin: 5px 0;"><strong>📅 Έναρξη:</strong> {{start_date}}</p>
        <p style="margin: 5px 0;"><strong>📍 Τοποθεσία:</strong> {{location}}</p>
    </div>
    
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{mission_url}}" style="background-color: #4f46e5; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
            Δήλωση Συμμετοχής
        </a>
    </div>
    
    <p style="color: #999; font-size: 12px; margin-top: 40px; text-align: center;">
        Αυτό το email στάλθηκε από το {{app_name}}
    </p>
</div>
HTML;
    }
}
