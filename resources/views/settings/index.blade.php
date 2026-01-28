@extends('layouts.app')

@section('title', 'Ρυθμίσεις Συστήματος')
@section('page-title', 'Ρυθμίσεις')

@section('content')
<div class="row">
    <div class="col-12">
        <!-- Tabs Navigation -->
        <ul class="nav nav-tabs flex-wrap mb-4" id="settingsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $tab === 'email' ? 'active' : '' }}" 
                        id="email-tab" 
                        data-bs-toggle="tab" 
                        data-bs-target="#email" 
                        type="button" 
                        role="tab">
                    <i class="bi bi-envelope me-2"></i>Email
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $tab === 'notifications' ? 'active' : '' }}" 
                        id="notifications-tab" 
                        data-bs-toggle="tab" 
                        data-bs-target="#notifications" 
                        type="button" 
                        role="tab">
                    <i class="bi bi-bell me-2"></i>Ειδοποιήσεις
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $tab === 'general' ? 'active' : '' }}" 
                        id="general-tab" 
                        data-bs-toggle="tab" 
                        data-bs-target="#general" 
                        type="button" 
                        role="tab">
                    <i class="bi bi-gear me-2"></i>Γενικά
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $tab === 'departments' ? 'active' : '' }}" 
                        id="departments-tab" 
                        data-bs-toggle="tab" 
                        data-bs-target="#departments" 
                        type="button" 
                        role="tab">
                    <i class="bi bi-building me-2"></i>Τμήματα
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $tab === 'templates' ? 'active' : '' }}" 
                        id="templates-tab" 
                        data-bs-toggle="tab" 
                        data-bs-target="#templates" 
                        type="button" 
                        role="tab">
                    <i class="bi bi-file-earmark-text me-2"></i>Email Templates
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link" href="{{ route('settings.updates') }}">
                    <i class="bi bi-cloud-download me-2"></i>Ενημερώσεις
                    @php
                        $updateService = app(\App\Services\UpdateService::class);
                        $updateInfo = $updateService->checkForUpdates();
                    @endphp
                    @if($updateInfo['success'] && ($updateInfo['has_update'] ?? false))
                        <span class="badge bg-success ms-1">Νέα!</span>
                    @endif
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="settingsTabContent">
            <!-- Email Settings Tab -->
            <div class="tab-pane fade {{ $tab === 'email' ? 'show active' : '' }}" id="email" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-envelope-gear me-2"></i>Ρυθμίσεις SMTP Email
                        </h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('settings.email.update') }}" method="POST">
                            @csrf
                            @method('PUT')
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="mail_mailer" class="form-label">Mail Driver</label>
                                        <select class="form-select @error('mail_mailer') is-invalid @enderror" 
                                                id="mail_mailer" 
                                                name="mail_mailer">
                                            <option value="smtp" {{ ($emailSettings['mail_mailer'] ?? '') === 'smtp' ? 'selected' : '' }}>SMTP</option>
                                            <option value="sendmail" {{ ($emailSettings['mail_mailer'] ?? '') === 'sendmail' ? 'selected' : '' }}>Sendmail</option>
                                            <option value="mailgun" {{ ($emailSettings['mail_mailer'] ?? '') === 'mailgun' ? 'selected' : '' }}>Mailgun</option>
                                            <option value="ses" {{ ($emailSettings['mail_mailer'] ?? '') === 'ses' ? 'selected' : '' }}>Amazon SES</option>
                                            <option value="log" {{ ($emailSettings['mail_mailer'] ?? '') === 'log' ? 'selected' : '' }}>Log (Testing)</option>
                                        </select>
                                        @error('mail_mailer')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row" id="smtp-settings">
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label for="mail_host" class="form-label">SMTP Host</label>
                                        <input type="text" 
                                               class="form-control @error('mail_host') is-invalid @enderror" 
                                               id="mail_host" 
                                               name="mail_host" 
                                               value="{{ old('mail_host', $emailSettings['mail_host'] ?? 'smtp.gmail.com') }}"
                                               placeholder="smtp.gmail.com">
                                        @error('mail_host')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="mail_port" class="form-label">SMTP Port</label>
                                        <input type="number" 
                                               class="form-control @error('mail_port') is-invalid @enderror" 
                                               id="mail_port" 
                                               name="mail_port" 
                                               value="{{ old('mail_port', $emailSettings['mail_port'] ?? 587) }}"
                                               placeholder="587">
                                        @error('mail_port')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="mail_username" class="form-label">SMTP Username</label>
                                        <input type="text" 
                                               class="form-control @error('mail_username') is-invalid @enderror" 
                                               id="mail_username" 
                                               name="mail_username" 
                                               value="{{ old('mail_username', $emailSettings['mail_username'] ?? '') }}"
                                               placeholder="your-email@gmail.com">
                                        @error('mail_username')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="mail_password" class="form-label">SMTP Password</label>
                                        <div class="input-group">
                                            <input type="password" 
                                                   class="form-control @error('mail_password') is-invalid @enderror" 
                                                   id="mail_password" 
                                                   name="mail_password" 
                                                   placeholder="••••••••">
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword()">
                                                <i class="bi bi-eye" id="toggleIcon"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted">Αφήστε κενό για να διατηρήσετε τον υπάρχοντα κωδικό</small>
                                        @error('mail_password')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="mail_encryption" class="form-label">Encryption</label>
                                        <select class="form-select @error('mail_encryption') is-invalid @enderror" 
                                                id="mail_encryption" 
                                                name="mail_encryption">
                                            <option value="tls" {{ ($emailSettings['mail_encryption'] ?? '') === 'tls' ? 'selected' : '' }}>TLS</option>
                                            <option value="ssl" {{ ($emailSettings['mail_encryption'] ?? '') === 'ssl' ? 'selected' : '' }}>SSL</option>
                                            <option value="null" {{ ($emailSettings['mail_encryption'] ?? '') === 'null' ? 'selected' : '' }}>Κανένα</option>
                                        </select>
                                        @error('mail_encryption')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">
                            <h6 class="mb-3">Στοιχεία Αποστολέα</h6>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="mail_from_address" class="form-label">Email Αποστολέα</label>
                                        <input type="email" 
                                               class="form-control @error('mail_from_address') is-invalid @enderror" 
                                               id="mail_from_address" 
                                               name="mail_from_address" 
                                               value="{{ old('mail_from_address', $emailSettings['mail_from_address'] ?? 'noreply@volunteerops.gr') }}"
                                               placeholder="noreply@volunteerops.gr"
                                               required>
                                        @error('mail_from_address')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="mail_from_name" class="form-label">Όνομα Αποστολέα</label>
                                        <input type="text" 
                                               class="form-control @error('mail_from_name') is-invalid @enderror" 
                                               id="mail_from_name" 
                                               name="mail_from_name" 
                                               value="{{ old('mail_from_name', $emailSettings['mail_from_name'] ?? 'VolunteerOps') }}"
                                               placeholder="VolunteerOps"
                                               required>
                                        @error('mail_from_name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-2"></i>Αποθήκευση
                                </button>
                            </div>
                        </form>

                        <hr class="my-4">
                        
                        <!-- Test Email -->
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">
                                    <i class="bi bi-send me-2"></i>Δοκιμαστικό Email
                                </h6>
                                <form action="{{ route('settings.email.test') }}" method="POST" class="row g-3 align-items-end">
                                    @csrf
                                    <div class="col-md-6">
                                        <label for="test_email" class="form-label">Email Παραλήπτη</label>
                                        <input type="email" 
                                               class="form-control" 
                                               id="test_email" 
                                               name="test_email" 
                                               placeholder="test@example.com"
                                               value="{{ auth()->user()->email }}"
                                               required>
                                    </div>
                                    <div class="col-md-6">
                                        <button type="submit" class="btn btn-outline-primary">
                                            <i class="bi bi-send me-2"></i>Αποστολή Δοκιμαστικού
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notifications Tab -->
            <div class="tab-pane fade {{ $tab === 'notifications' ? 'show active' : '' }}" id="notifications" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-bell-gear me-2"></i>Ρυθμίσεις Ειδοποιήσεων
                        </h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('settings.notifications.update') }}" method="POST">
                            @csrf
                            @method('PUT')
                            
                            <!-- Κανάλια Ειδοποιήσεων -->
                            <h6 class="mb-3"><i class="bi bi-broadcast me-2"></i>Κανάλια Ειδοποιήσεων</h6>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="notify_inapp_enabled" 
                                               name="notify_inapp_enabled" value="1"
                                               {{ ($notificationSettings['notify_inapp_enabled'] ?? true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="notify_inapp_enabled">
                                            <i class="bi bi-app-indicator me-1"></i>Ειδοποιήσεις εντός εφαρμογής
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="notify_email_enabled" 
                                               name="notify_email_enabled" value="1"
                                               {{ ($notificationSettings['notify_email_enabled'] ?? false) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="notify_email_enabled">
                                            <i class="bi bi-envelope me-1"></i>Αποστολή ειδοποιήσεων μέσω email
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Αποστολές -->
                            <h6 class="mb-3"><i class="bi bi-flag me-2"></i>Αποστολές</h6>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="notify_new_mission" 
                                               name="notify_new_mission" value="1"
                                               {{ ($notificationSettings['notify_new_mission'] ?? true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="notify_new_mission">
                                            Ειδοποίηση για νέες αποστολές
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="notify_mission_update" 
                                               name="notify_mission_update" value="1"
                                               {{ ($notificationSettings['notify_mission_update'] ?? true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="notify_mission_update">
                                            Ειδοποίηση για ενημερώσεις αποστολών
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Βάρδιες -->
                            <h6 class="mb-3"><i class="bi bi-calendar-event me-2"></i>Βάρδιες</h6>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="notify_shift_reminder" 
                                               name="notify_shift_reminder" value="1"
                                               {{ ($notificationSettings['notify_shift_reminder'] ?? true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="notify_shift_reminder">
                                            Υπενθύμιση πριν την βάρδια
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="notify_shift_reminder_hours" class="form-label">Ώρες πριν την υπενθύμιση</label>
                                        <input type="number" class="form-control" id="notify_shift_reminder_hours" 
                                               name="notify_shift_reminder_hours" 
                                               value="{{ $notificationSettings['notify_shift_reminder_hours'] ?? 24 }}"
                                               min="1" max="168">
                                        <small class="text-muted">1-168 ώρες (1 εβδομάδα)</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Συμμετοχές -->
                            <h6 class="mb-3"><i class="bi bi-person-check me-2"></i>Συμμετοχές</h6>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="notify_participation_approved" 
                                               name="notify_participation_approved" value="1"
                                               {{ ($notificationSettings['notify_participation_approved'] ?? true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="notify_participation_approved">
                                            Ειδοποίηση έγκρισης συμμετοχής
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="notify_participation_rejected" 
                                               name="notify_participation_rejected" value="1"
                                               {{ ($notificationSettings['notify_participation_rejected'] ?? true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="notify_participation_rejected">
                                            Ειδοποίηση απόρριψης συμμετοχής
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Διαχειριστές -->
                            <h6 class="mb-3"><i class="bi bi-shield-check me-2"></i>Για Διαχειριστές</h6>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="notify_new_volunteer" 
                                               name="notify_new_volunteer" value="1"
                                               {{ ($notificationSettings['notify_new_volunteer'] ?? true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="notify_new_volunteer">
                                            Ειδοποίηση για νέους εθελοντές
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-2"></i>Αποθήκευση
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- General Tab -->
            <div class="tab-pane fade {{ $tab === 'general' ? 'show active' : '' }}" id="general" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-sliders me-2"></i>Γενικές Ρυθμίσεις
                        </h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('settings.general.update') }}" method="POST">
                            @csrf
                            @method('PUT')

                            <!-- Εφαρμογή -->
                            <h6 class="mb-3"><i class="bi bi-app me-2"></i>Εφαρμογή</h6>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="app_name" class="form-label">Όνομα Εφαρμογής</label>
                                        <input type="text" class="form-control @error('app_name') is-invalid @enderror" 
                                               id="app_name" name="app_name" 
                                               value="{{ old('app_name', $generalSettings['app_name'] ?? 'VolunteerOps') }}">
                                        @error('app_name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="app_timezone" class="form-label">Ζώνη Ώρας</label>
                                        <select class="form-select @error('app_timezone') is-invalid @enderror" 
                                                id="app_timezone" name="app_timezone">
                                            <option value="Europe/Athens" {{ ($generalSettings['app_timezone'] ?? 'Europe/Athens') === 'Europe/Athens' ? 'selected' : '' }}>Ελλάδα (Europe/Athens)</option>
                                            <option value="Europe/London" {{ ($generalSettings['app_timezone'] ?? '') === 'Europe/London' ? 'selected' : '' }}>UK (Europe/London)</option>
                                            <option value="Europe/Berlin" {{ ($generalSettings['app_timezone'] ?? '') === 'Europe/Berlin' ? 'selected' : '' }}>Κεντρική Ευρώπη (Europe/Berlin)</option>
                                            <option value="UTC" {{ ($generalSettings['app_timezone'] ?? '') === 'UTC' ? 'selected' : '' }}>UTC</option>
                                        </select>
                                        @error('app_timezone')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="app_date_format" class="form-label">Μορφή Ημερομηνίας</label>
                                        <select class="form-select @error('app_date_format') is-invalid @enderror" 
                                                id="app_date_format" name="app_date_format">
                                            <option value="d/m/Y" {{ ($generalSettings['app_date_format'] ?? 'd/m/Y') === 'd/m/Y' ? 'selected' : '' }}>27/01/2026</option>
                                            <option value="d-m-Y" {{ ($generalSettings['app_date_format'] ?? '') === 'd-m-Y' ? 'selected' : '' }}>27-01-2026</option>
                                            <option value="Y-m-d" {{ ($generalSettings['app_date_format'] ?? '') === 'Y-m-d' ? 'selected' : '' }}>2026-01-27</option>
                                            <option value="d M Y" {{ ($generalSettings['app_date_format'] ?? '') === 'd M Y' ? 'selected' : '' }}>27 Ιαν 2026</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="app_time_format" class="form-label">Μορφή Ώρας</label>
                                        <select class="form-select @error('app_time_format') is-invalid @enderror" 
                                                id="app_time_format" name="app_time_format">
                                            <option value="H:i" {{ ($generalSettings['app_time_format'] ?? 'H:i') === 'H:i' ? 'selected' : '' }}>14:30 (24ωρο)</option>
                                            <option value="h:i A" {{ ($generalSettings['app_time_format'] ?? '') === 'h:i A' ? 'selected' : '' }}>02:30 PM (12ωρο)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Οργανισμός -->
                            <h6 class="mb-3"><i class="bi bi-building me-2"></i>Στοιχεία Οργανισμού</h6>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="organization_name" class="form-label">Όνομα Οργανισμού</label>
                                        <input type="text" class="form-control @error('organization_name') is-invalid @enderror" 
                                               id="organization_name" name="organization_name" 
                                               value="{{ old('organization_name', $generalSettings['organization_name'] ?? 'Εθελοντική Ομάδα') }}">
                                        @error('organization_name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="organization_phone" class="form-label">Τηλέφωνο Επικοινωνίας</label>
                                        <input type="text" class="form-control @error('organization_phone') is-invalid @enderror" 
                                               id="organization_phone" name="organization_phone" 
                                               value="{{ old('organization_phone', $generalSettings['organization_phone'] ?? '') }}"
                                               placeholder="+30 210 1234567">
                                        @error('organization_phone')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-12">
                                    <div class="mb-3">
                                        <label for="organization_address" class="form-label">Διεύθυνση</label>
                                        <textarea class="form-control @error('organization_address') is-invalid @enderror" 
                                                  id="organization_address" name="organization_address" 
                                                  rows="2" placeholder="Οδός, Αριθμός, Πόλη, ΤΚ">{{ old('organization_address', $generalSettings['organization_address'] ?? '') }}</textarea>
                                        @error('organization_address')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Εθελοντές & Βάρδιες -->
                            <h6 class="mb-3"><i class="bi bi-people me-2"></i>Εθελοντές & Βάρδιες</h6>
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="volunteers_require_approval" 
                                               name="volunteers_require_approval" value="1"
                                               {{ ($generalSettings['volunteers_require_approval'] ?? true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="volunteers_require_approval">
                                            Οι νέοι εθελοντές απαιτούν έγκριση
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="max_shifts_per_volunteer" class="form-label">Μέγιστες βάρδιες/εβδομάδα</label>
                                        <input type="number" class="form-control @error('max_shifts_per_volunteer') is-invalid @enderror" 
                                               id="max_shifts_per_volunteer" name="max_shifts_per_volunteer" 
                                               value="{{ old('max_shifts_per_volunteer', $generalSettings['max_shifts_per_volunteer'] ?? 5) }}"
                                               min="1" max="20">
                                        @error('max_shifts_per_volunteer')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="default_shift_duration" class="form-label">Προεπιλεγμένη διάρκεια βάρδιας (ώρες)</label>
                                        <input type="number" class="form-control @error('default_shift_duration') is-invalid @enderror" 
                                               id="default_shift_duration" name="default_shift_duration" 
                                               value="{{ old('default_shift_duration', $generalSettings['default_shift_duration'] ?? 4) }}"
                                               min="1" max="24">
                                        @error('default_shift_duration')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Σύστημα -->
                            <h6 class="mb-3"><i class="bi bi-gear me-2"></i>Σύστημα</h6>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="maintenance_mode" 
                                               name="maintenance_mode" value="1"
                                               {{ ($generalSettings['maintenance_mode'] ?? false) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="maintenance_mode">
                                            <i class="bi bi-tools me-1 text-warning"></i>Λειτουργία Συντήρησης
                                        </label>
                                        <small class="d-block text-muted">Απενεργοποιεί την πρόσβαση για μη-διαχειριστές</small>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-2"></i>Αποθήκευση
                                </button>
                            </div>
                        </form>

                        <hr class="my-4">

                        <!-- Τύποι Αποστολών -->
                        <h6 class="mb-3"><i class="bi bi-tags me-2"></i>Τύποι Αποστολών</h6>
                        <p class="text-muted small">Διαχειριστείτε τους διαθέσιμους τύπους αποστολών που εμφανίζονται κατά τη δημιουργία αποστολών.</p>
                        
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <div class="list-group mb-3">
                                    @foreach($missionTypes ?? ['Εθελοντική', 'Υγειονομική'] as $type)
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-tag me-2 text-primary"></i>{{ $type }}</span>
                                            @if(count($missionTypes ?? []) > 1)
                                                <form action="{{ route('settings.mission-types.remove') }}" method="POST" class="d-inline" 
                                                      onsubmit="return confirm('Θέλετε να αφαιρέσετε τον τύπο «{{ $type }}»;')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="mission_type" value="{{ $type }}">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                
                                <form action="{{ route('settings.mission-types.add') }}" method="POST" class="row g-2 align-items-end">
                                    @csrf
                                    <div class="col">
                                        <label for="mission_type" class="form-label">Νέος Τύπος Αποστολής</label>
                                        <input type="text" class="form-control" id="mission_type" name="mission_type" 
                                               placeholder="π.χ. Εκπαιδευτική" required>
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-plus-lg me-1"></i>Προσθήκη
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Departments Tab -->
            <div class="tab-pane fade {{ $tab === 'departments' ? 'show active' : '' }}" id="departments" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-building me-2"></i>Διαχείριση Τμημάτων
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-4">Διαχειριστείτε τα τμήματα του οργανισμού. Τα τμήματα χρησιμοποιούνται για την οργάνωση εθελοντών και αποστολών.</p>
                        
                        <!-- Λίστα Τμημάτων -->
                        <div class="row">
                            <div class="col-lg-8">
                                <div class="table-responsive mb-4">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Όνομα</th>
                                                <th>Περιγραφή</th>
                                                <th>Εθελοντές</th>
                                                <th>Κατάσταση</th>
                                                <th class="text-end">Ενέργειες</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($departments ?? [] as $department)
                                                <tr>
                                                    <td>
                                                        <i class="bi bi-building text-primary me-2"></i>
                                                        <strong>{{ $department->name }}</strong>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted">{{ Str::limit($department->description, 50) ?? '-' }}</small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info">{{ $department->users_count ?? 0 }}</span>
                                                    </td>
                                                    <td>
                                                        <span class="badge {{ $department->is_active ? 'bg-success' : 'bg-secondary' }}">
                                                            {{ $department->is_active ? 'Ενεργό' : 'Ανενεργό' }}
                                                        </span>
                                                    </td>
                                                    <td class="text-end">
                                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editDepartmentModal{{ $department->id }}">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        @if(($department->users_count ?? 0) === 0)
                                                            <form action="{{ route('settings.departments.remove') }}" method="POST" class="d-inline"
                                                                  onsubmit="return confirm('Θέλετε να διαγράψετε το τμήμα «{{ $department->name }}»;')">
                                                                @csrf
                                                                @method('DELETE')
                                                                <input type="hidden" name="department_id" value="{{ $department->id }}">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </form>
                                                        @else
                                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled 
                                                                    title="Δεν μπορεί να διαγραφεί - έχει εθελοντές">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        @endif
                                                    </td>
                                                </tr>
                                                
                                                <!-- Edit Modal -->
                                                <div class="modal fade" id="editDepartmentModal{{ $department->id }}" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <form action="{{ route('settings.departments.update') }}" method="POST">
                                                                @csrf
                                                                @method('PUT')
                                                                <input type="hidden" name="department_id" value="{{ $department->id }}">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title">Επεξεργασία Τμήματος</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <div class="mb-3">
                                                                        <label for="name{{ $department->id }}" class="form-label">Όνομα Τμήματος</label>
                                                                        <input type="text" class="form-control" id="name{{ $department->id }}" 
                                                                               name="name" value="{{ $department->name }}" required>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="description{{ $department->id }}" class="form-label">Περιγραφή</label>
                                                                        <textarea class="form-control" id="description{{ $department->id }}" 
                                                                                  name="description" rows="3">{{ $department->description }}</textarea>
                                                                    </div>
                                                                    <div class="form-check form-switch">
                                                                        <input class="form-check-input" type="checkbox" id="is_active{{ $department->id }}" 
                                                                               name="is_active" value="1" {{ $department->is_active ? 'checked' : '' }}>
                                                                        <label class="form-check-label" for="is_active{{ $department->id }}">Ενεργό</label>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                                                                    <button type="submit" class="btn btn-primary">Αποθήκευση</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            @empty
                                                <tr>
                                                    <td colspan="5" class="text-center py-4 text-muted">
                                                        <i class="bi bi-building fs-1 d-block mb-2"></i>
                                                        Δεν υπάρχουν τμήματα
                                                    </td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Προσθήκη Νέου Τμήματος -->
                                <div class="card bg-light">
                                    <div class="card-header">
                                        <h6 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Προσθήκη Νέου Τμήματος</h6>
                                    </div>
                                    <div class="card-body">
                                        <form action="{{ route('settings.departments.add') }}" method="POST">
                                            @csrf
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label for="new_department_name" class="form-label">Όνομα Τμήματος *</label>
                                                    <input type="text" class="form-control" id="new_department_name" 
                                                           name="name" placeholder="π.χ. Τμήμα Πρώτων Βοηθειών" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="new_department_description" class="form-label">Περιγραφή</label>
                                                    <input type="text" class="form-control" id="new_department_description" 
                                                           name="description" placeholder="Σύντομη περιγραφή του τμήματος">
                                                </div>
                                                <div class="col-md-2 d-flex align-items-end">
                                                    <button type="submit" class="btn btn-success w-100">
                                                        <i class="bi bi-plus-lg me-1"></i>Προσθήκη
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Email Templates Tab -->
            <div class="tab-pane fade {{ $tab === 'templates' ? 'show active' : '' }}" id="templates" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-file-earmark-text me-2"></i>Πρότυπα Email
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-4">
                            Διαχειριστείτε τα πρότυπα email που αποστέλλονται από την εφαρμογή. 
                            Χρησιμοποιήστε τα placeholders (π.χ. <code>@{{volunteer_name}}</code>) για δυναμικό περιεχόμενο.
                        </p>

                        <!-- Email Logo Setting -->
                        <div class="card bg-light mb-4">
                            <div class="card-body">
                                <h6 class="mb-3"><i class="bi bi-image me-2"></i>Logo Email</h6>
                                <form action="{{ route('settings.email-logo.update') }}" method="POST" enctype="multipart/form-data">
                                    @csrf
                                    @method('PUT')
                                    <div class="row align-items-end">
                                        <div class="col-md-4">
                                            @if($emailLogo)
                                                <div class="mb-2">
                                                    <img src="{{ $emailLogo }}" alt="Email Logo" style="max-height: 60px; max-width: 200px;" class="border rounded p-2 bg-white">
                                                </div>
                                            @else
                                                <div class="mb-2 text-muted">
                                                    <i class="bi bi-image fs-1"></i>
                                                    <small class="d-block">Χωρίς logo</small>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="col-md-5">
                                            <label for="email_logo" class="form-label">Ανέβασμα νέου logo</label>
                                            <input type="file" class="form-control" id="email_logo" name="email_logo" accept="image/*">
                                            <small class="text-muted">PNG ή JPG, max 500KB, προτεινόμενο μέγεθος: 200x60px</small>
                                        </div>
                                        <div class="col-md-3">
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="bi bi-upload me-1"></i>Αποθήκευση
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Templates List -->
                        <div class="accordion" id="templatesAccordion">
                            @forelse($emailTemplates ?? [] as $template)
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" 
                                                data-bs-toggle="collapse" 
                                                data-bs-target="#template_{{ $template->id }}">
                                            <span class="me-3">
                                                @if($template->is_active)
                                                    <span class="badge bg-success">Ενεργό</span>
                                                @else
                                                    <span class="badge bg-secondary">Ανενεργό</span>
                                                @endif
                                            </span>
                                            <strong>{{ $template->name }}</strong>
                                            <small class="text-muted ms-2">({{ $template->code }})</small>
                                        </button>
                                    </h2>
                                    <div id="template_{{ $template->id }}" class="accordion-collapse collapse" 
                                         data-bs-parent="#templatesAccordion">
                                        <div class="accordion-body">
                                            <form action="{{ route('settings.email-template.update', $template) }}" method="POST">
                                                @csrf
                                                @method('PUT')
                                                
                                                <div class="row">
                                                    <div class="col-md-8">
                                                        <p class="text-muted small mb-3">{{ $template->description }}</p>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Θέμα Email</label>
                                                            <input type="text" class="form-control" name="subject" 
                                                                   value="{{ $template->subject }}" required>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Περιεχόμενο Email</label>
                                                            <textarea class="form-control summernote-editor" name="body" 
                                                                      id="editor_{{ $template->id }}">{{ $template->body }}</textarea>
                                                        </div>
                                                        
                                                        <div class="form-check mb-3">
                                                            <input class="form-check-input" type="checkbox" name="is_active" 
                                                                   id="active_{{ $template->id }}" {{ $template->is_active ? 'checked' : '' }}>
                                                            <label class="form-check-label" for="active_{{ $template->id }}">
                                                                Ενεργό
                                                            </label>
                                                        </div>
                                                        
                                                        <button type="submit" class="btn btn-primary">
                                                            <i class="bi bi-check-lg me-1"></i>Αποθήκευση
                                                        </button>
                                                        <button type="button" class="btn btn-outline-info ms-2" 
                                                                onclick="previewTemplate({{ $template->id }})">
                                                            <i class="bi bi-eye me-1"></i>Προεπισκόπηση
                                                        </button>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="card bg-light">
                                                            <div class="card-header">
                                                                <small class="fw-bold">Διαθέσιμα Placeholders</small>
                                                            </div>
                                                            <div class="card-body p-2">
                                                                <table class="table table-sm table-borderless mb-0" style="font-size: 12px;">
                                                                    @foreach($template->getPlaceholders() as $placeholder => $desc)
                                                                        <tr>
                                                                            <td><code>{{ $placeholder }}</code></td>
                                                                            <td class="text-muted">{{ $desc }}</td>
                                                                        </tr>
                                                                    @endforeach
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Δεν υπάρχουν πρότυπα email. Εκτελέστε <code>php artisan db:seed --class=EmailTemplatesSeeder</code>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Email Preview Modal -->
<div class="modal fade" id="emailPreviewModal" tabindex="-1" aria-labelledby="emailPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title" id="emailPreviewModalLabel">
                    <i class="bi bi-envelope-open me-2"></i>Προεπισκόπηση Email
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="bg-secondary bg-opacity-10 p-3">
                    <div class="bg-white mx-auto shadow" style="max-width: 650px;">
                        <div id="emailPreviewContent" style="min-height: 400px; padding: 20px;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Κλείσιμο</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('styles')
<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.css" rel="stylesheet">
<style>
    .note-editor.note-frame {
        border-radius: 0.375rem;
        border-color: #dee2e6;
    }
    .note-editor .note-toolbar {
        background-color: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
    }
    .note-editor .note-editing-area {
        background-color: #fff;
    }
    .note-editor .note-editable {
        font-size: 14px;
        line-height: 1.6;
    }
    .note-placeholder {
        color: #6c757d;
    }
</style>
@endpush

@push('scripts')
<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/lang/summernote-el-GR.min.js"></script>
<script>
    // Initialize Summernote editors
    $(document).ready(function() {
        $('.summernote-editor').each(function() {
            $(this).summernote({
                lang: 'el-GR',
                height: 300,
                placeholder: 'Γράψτε το περιεχόμενο του email...',
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
                    ['fontname', ['fontname']],
                    ['fontsize', ['fontsize']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture', 'hr']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ],
                fontNames: ['Arial', 'Arial Black', 'Comic Sans MS', 'Courier New', 'Georgia', 'Helvetica', 'Impact', 'Tahoma', 'Times New Roman', 'Verdana'],
                fontSizes: ['8', '9', '10', '11', '12', '14', '16', '18', '20', '24', '28', '32', '36', '48'],
                styleTags: ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
                callbacks: {
                    onInit: function() {
                        // Ensure the editor is properly initialized
                    }
                }
            });
        });
    });

    // Preview template function
    function previewTemplate(templateId) {
        const editorContent = $('#editor_' + templateId).summernote('code');
        
        // Sample data for preview
        const sampleData = {
            '@{{volunteer_name}}': 'Γιώργος Παπαδόπουλος',
            '@{{volunteer_email}}': 'george@example.com',
            '@{{mission_title}}': 'Αποστολή Βοήθειας',
            '@{{mission_description}}': 'Περιγραφή της αποστολής...',
            '@{{shift_date}}': '15/02/2026',
            '@{{shift_time}}': '09:00 - 17:00',
            '@{{shift_location}}': 'Κέντρο Εθελοντισμού, Αθήνα',
            '@{{department_name}}': 'Τμήμα Υγείας',
            '@{{app_name}}': '{{ \App\Models\Setting::get("app_name", "VolunteerOps") }}',
            '@{{app_url}}': '{{ url("/") }}',
            '@{{current_date}}': '{{ now()->format("d/m/Y") }}',
            '@{{admin_name}}': 'Διαχειριστής Συστήματος'
        };
        
        // Replace placeholders with sample data
        let previewHtml = editorContent;
        for (const [placeholder, value] of Object.entries(sampleData)) {
            previewHtml = previewHtml.replace(new RegExp(placeholder.replace(/[{}]/g, '\\$&'), 'g'), 
                '<span class="bg-warning bg-opacity-25 px-1 rounded">' + value + '</span>');
        }
        
        // Set preview content
        $('#emailPreviewContent').html(previewHtml);
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('emailPreviewModal'));
        modal.show();
    }

    function togglePassword() {
        const input = document.getElementById('mail_password');
        const icon = document.getElementById('toggleIcon');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    }

    // Show/hide SMTP settings based on mail driver
    document.getElementById('mail_mailer')?.addEventListener('change', function() {
        const smtpSettings = document.getElementById('smtp-settings');
        if (this.value === 'smtp') {
            smtpSettings.style.display = 'flex';
        } else {
            smtpSettings.style.display = 'none';
        }
    });
</script>
@endpush
