<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Models\EmailTemplate;
use App\Services\SettingsService;
use App\Services\UpdateService;
use App\Modules\Directory\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function __construct(
        protected SettingsService $settingsService,
        protected UpdateService $updateService
    ) {
        // Μόνο System Admin έχει πρόσβαση
        $this->middleware(function ($request, $next) {
            if (!auth()->user()->hasRole(User::ROLE_SYSTEM_ADMIN)) {
                abort(403, 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
            }
            return $next($request);
        });
    }

    /**
     * Κεντρική σελίδα ρυθμίσεων.
     */
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'email');
        $data = $this->settingsService->getSettingsPageData();
        
        return view('settings.index', array_merge(['tab' => $tab], $data));
    }

    /**
     * Αποθήκευση email settings.
     */
    public function updateEmail(Request $request)
    {
        $validated = $request->validate([
            'mail_mailer' => 'required|string|in:smtp,sendmail,mailgun,ses,log',
            'mail_host' => 'required_if:mail_mailer,smtp|nullable|string|max:255',
            'mail_port' => 'required_if:mail_mailer,smtp|nullable|integer|min:1|max:65535',
            'mail_username' => 'nullable|string|max:255',
            'mail_password' => 'nullable|string|max:255',
            'mail_encryption' => 'nullable|string|in:tls,ssl,null',
            'mail_from_address' => 'required|email|max:255',
            'mail_from_name' => 'required|string|max:255',
        ]);

        $this->settingsService->updateEmailSettings($validated);

        return back()->with('success', 'Οι ρυθμίσεις email αποθηκεύτηκαν επιτυχώς.');
    }

    /**
     * Δοκιμαστικό email.
     */
    public function testEmail(Request $request)
    {
        $validated = $request->validate([
            'test_email' => 'required|email',
        ]);

        try {
            $this->settingsService->sendTestEmail($validated['test_email']);
            return back()->with('success', 'Το δοκιμαστικό email στάλθηκε επιτυχώς στο ' . $validated['test_email']);
        } catch (\Exception $e) {
            return back()->with('error', 'Σφάλμα αποστολής: ' . $e->getMessage());
        }
    }
    }

    /**
     * Αποθήκευση notification settings.
     */
    public function updateNotifications(Request $request)
    {
        $validated = $request->validate([
            'notify_new_mission' => 'boolean',
            'notify_mission_update' => 'boolean',
            'notify_shift_reminder' => 'boolean',
            'notify_shift_reminder_hours' => 'required|integer|min:1|max:168',
            'notify_participation_approved' => 'boolean',
            'notify_participation_rejected' => 'boolean',
            'notify_new_volunteer' => 'boolean',
            'notify_email_enabled' => 'boolean',
            'notify_inapp_enabled' => 'boolean',
        ]);

        // Για checkboxes που δεν στέλνονται αν είναι unchecked
        $booleanFields = [
            'notify_new_mission', 'notify_mission_update', 'notify_shift_reminder',
            'notify_participation_approved', 'notify_participation_rejected',
            'notify_new_volunteer', 'notify_email_enabled', 'notify_inapp_enabled'
        ];

        foreach ($booleanFields as $field) {
            $value = $request->has($field) ? '1' : '0';
            Setting::set($field, $value, [
                'group' => Setting::GROUP_NOTIFICATIONS,
                'type' => 'boolean',
            ]);
        }

        Setting::set('notify_shift_reminder_hours', $validated['notify_shift_reminder_hours'], [
            'group' => Setting::GROUP_NOTIFICATIONS,
            'type' => 'integer',
        ]);

        return back()->with('success', 'Οι ρυθμίσεις ειδοποιήσεων αποθηκεύτηκαν επιτυχώς.');
    }

    /**
     * Αποθήκευση general settings.
     */
    public function updateGeneral(Request $request)
    {
        $validated = $request->validate([
            'app_name' => 'required|string|max:100',
            'app_timezone' => 'required|string|max:50',
            'app_date_format' => 'required|string|max:20',
            'app_time_format' => 'required|string|max:20',
            'volunteers_require_approval' => 'boolean',
            'max_shifts_per_volunteer' => 'required|integer|min:1|max:20',
            'default_shift_duration' => 'required|integer|min:1|max:24',
            'organization_name' => 'required|string|max:255',
            'organization_phone' => 'nullable|string|max:50',
            'organization_address' => 'nullable|string|max:500',
            'maintenance_mode' => 'boolean',
        ]);

        foreach ($validated as $key => $value) {
            $type = in_array($key, ['volunteers_require_approval', 'maintenance_mode']) ? 'boolean' : 
                   (in_array($key, ['max_shifts_per_volunteer', 'default_shift_duration']) ? 'integer' : 'string');
            
            // Για booleans που δεν στέλνονται αν είναι unchecked
            if ($type === 'boolean') {
                $value = $request->has($key) ? '1' : '0';
            }
            
            Setting::set($key, $value, [
                'group' => Setting::GROUP_GENERAL,
                'type' => $type,
            ]);
        }

        return back()->with('success', 'Οι γενικές ρυθμίσεις αποθηκεύτηκαν επιτυχώς.');
    }

    /**
     * Προσθήκη τύπου αποστολής.
     */
    public function addMissionType(Request $request)
    {
        $validated = $request->validate([
            'mission_type' => 'required|string|max:100',
        ]);

        $types = Setting::getMissionTypes();
        $newType = trim($validated['mission_type']);
        
        if (in_array($newType, $types)) {
            return back()->with('error', 'Αυτός ο τύπος υπάρχει ήδη.');
        }
        
        $types[] = $newType;
        
        Setting::set('mission_types', json_encode($types, JSON_UNESCAPED_UNICODE), [
            'group' => Setting::GROUP_GENERAL,
            'type' => 'json',
        ]);

        return back()->with('success', "Ο τύπος «{$newType}» προστέθηκε επιτυχώς.");
    }

    /**
     * Αφαίρεση τύπου αποστολής.
     */
    public function removeMissionType(Request $request)
    {
        $validated = $request->validate([
            'mission_type' => 'required|string|max:100',
        ]);

        $types = Setting::getMissionTypes();
        $typeToRemove = $validated['mission_type'];
        
        if (count($types) <= 1) {
            return back()->with('error', 'Πρέπει να υπάρχει τουλάχιστον ένας τύπος αποστολής.');
        }
        
        $types = array_values(array_filter($types, fn($t) => $t !== $typeToRemove));
        
        Setting::set('mission_types', json_encode($types, JSON_UNESCAPED_UNICODE), [
            'group' => Setting::GROUP_GENERAL,
            'type' => 'json',
        ]);

        return back()->with('success', "Ο τύπος «{$typeToRemove}» αφαιρέθηκε επιτυχώς.");
    }

    /**
     * Προσθήκη τμήματος.
     */
    public function addDepartment(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:departments,name',
            'description' => 'nullable|string|max:500',
        ], [
            'name.required' => 'Το όνομα του τμήματος είναι υποχρεωτικό.',
            'name.unique' => 'Υπάρχει ήδη τμήμα με αυτό το όνομα.',
            'name.max' => 'Το όνομα δεν μπορεί να υπερβαίνει τους 255 χαρακτήρες.',
            'description.max' => 'Η περιγραφή δεν μπορεί να υπερβαίνει τους 500 χαρακτήρες.',
        ]);

        $department = Department::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return redirect()->route('settings.index', ['tab' => 'departments'])
            ->with('success', "Το τμήμα «{$department->name}» δημιουργήθηκε επιτυχώς.");
    }

    /**
     * Ενημέρωση τμήματος.
     */
    public function updateDepartment(Request $request)
    {
        $validated = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Το όνομα του τμήματος είναι υποχρεωτικό.',
            'name.max' => 'Το όνομα δεν μπορεί να υπερβαίνει τους 255 χαρακτήρες.',
            'department_id.exists' => 'Το τμήμα δεν βρέθηκε.',
        ]);

        $department = Department::findOrFail($validated['department_id']);
        
        // Έλεγχος αν υπάρχει άλλο τμήμα με το ίδιο όνομα
        $existingDept = Department::where('name', $validated['name'])
            ->where('id', '!=', $department->id)
            ->first();
            
        if ($existingDept) {
            return redirect()->route('settings.index', ['tab' => 'departments'])
                ->with('error', 'Υπάρχει ήδη τμήμα με αυτό το όνομα.');
        }

        $department->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('settings.index', ['tab' => 'departments'])
            ->with('success', "Το τμήμα «{$department->name}» ενημερώθηκε επιτυχώς.");
    }

    /**
     * Διαγραφή τμήματος.
     */
    public function removeDepartment(Request $request)
    {
        $validated = $request->validate([
            'department_id' => 'required|exists:departments,id',
        ], [
            'department_id.exists' => 'Το τμήμα δεν βρέθηκε.',
        ]);

        $department = Department::withCount('users')->findOrFail($validated['department_id']);
        
        // Έλεγχος αν έχει εθελοντές
        if ($department->users_count > 0) {
            return redirect()->route('settings.index', ['tab' => 'departments'])
                ->with('error', "Το τμήμα «{$department->name}» δεν μπορεί να διαγραφεί γιατί έχει {$department->users_count} εθελοντές.");
        }

        $departmentName = $department->name;
        $department->delete();

        return redirect()->route('settings.index', ['tab' => 'departments'])
            ->with('success', "Το τμήμα «{$departmentName}» διαγράφηκε επιτυχώς.");
    }

    /**
     * Ενημέρωση email template.
     */
    public function updateEmailTemplate(Request $request, EmailTemplate $template)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $template->update([
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('settings.index', ['tab' => 'templates'])
            ->with('success', "Το template «{$template->name}» ενημερώθηκε επιτυχώς.");
    }

    /**
     * Ενημέρωση email logo.
     */
    public function updateEmailLogo(Request $request)
    {
        $request->validate([
            'email_logo' => 'required|image|mimes:png,jpg,jpeg|max:512',
        ], [
            'email_logo.required' => 'Παρακαλώ επιλέξτε ένα αρχείο.',
            'email_logo.image' => 'Το αρχείο πρέπει να είναι εικόνα.',
            'email_logo.mimes' => 'Επιτρέπονται μόνο PNG και JPG.',
            'email_logo.max' => 'Το μέγεθος δεν πρέπει να υπερβαίνει τα 500KB.',
        ]);

        if ($request->hasFile('email_logo')) {
            // Διαγραφή παλιού logo
            $oldLogo = Setting::get('email_logo');
            if ($oldLogo && Storage::disk('public')->exists(str_replace('/storage/', '', $oldLogo))) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $oldLogo));
            }

            // Αποθήκευση νέου logo
            $path = $request->file('email_logo')->store('email', 'public');
            Setting::set('email_logo', '/storage/' . $path, ['group' => 'email']);
        }

        return redirect()->route('settings.index', ['tab' => 'templates'])
            ->with('success', 'Το logo email ενημερώθηκε επιτυχώς.');
    }

    /**
     * Έλεγχος για ενημερώσεις από το GitHub
     */
    public function checkUpdates()
    {
        $this->updateService->clearCache();
        $updateInfo = $this->updateService->checkForUpdates();
        $systemInfo = $this->updateService->getSystemInfo();
        
        return response()->json([
            'success' => true,
            'update' => $updateInfo,
            'system' => $systemInfo,
        ]);
    }

    /**
     * Σελίδα ενημερώσεων
     */
    public function updates()
    {
        $updateInfo = $this->updateService->checkForUpdates();
        $systemInfo = $this->updateService->getSystemInfo();
        $allReleases = $this->updateService->getAllReleases(5);
        
        return view('settings.updates', compact('updateInfo', 'systemInfo', 'allReleases'));
    }
}
