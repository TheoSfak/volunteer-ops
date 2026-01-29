@extends('layouts.app')

@section('title', 'Πίνακας Ελέγχου')
@section('page-title', 'Πίνακας Ελέγχου')

@section('content')
<div class="content-area">
    {{-- Year Selector --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0">
            <i class="bi bi-calendar3 me-2"></i>Στατιστικά {{ $selectedYear ?? now()->year }}
            @if(($selectedYear ?? now()->year) !== ($currentYear ?? now()->year))
                <span class="badge bg-secondary ms-2">Ιστορικό</span>
            @endif
        </h5>
        @if(!empty($availableYears) && count($availableYears) > 1)
        <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-calendar-range me-1"></i>{{ $selectedYear ?? now()->year }}
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                @foreach($availableYears as $year)
                    <li>
                        <a class="dropdown-item {{ ($selectedYear ?? now()->year) == $year ? 'active' : '' }}" 
                           href="{{ route('dashboard') }}?year={{ $year }}">
                            {{ $year }}
                            @if($year == ($currentYear ?? now()->year))
                                <span class="badge bg-primary ms-2">Τρέχον</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
        @endif
    </div>

    <!-- Main Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card" style="--accent-color: #4f46e5;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Ενεργές Αποστολές</div>
                        <div class="stat-value">{{ $stats['missions'] ?? 0 }}</div>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #eef2ff, #e0e7ff); color: #4f46e5;">
                        <i class="bi bi-flag-fill"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <a href="{{ route('missions.index') }}" class="text-decoration-none small text-primary fw-medium">
                        Προβολή όλων <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card" style="--accent-color: #10b981;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Εθελοντές</div>
                        <div class="stat-value">{{ $stats['volunteers'] ?? 0 }}</div>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #ecfdf5, #d1fae5); color: #10b981;">
                        <i class="bi bi-people-fill"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <a href="{{ route('volunteers.index') }}" class="text-decoration-none small text-success fw-medium">
                        Διαχείριση <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card" style="--accent-color: #f59e0b;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Εκκρεμείς Αιτήσεις</div>
                        <div class="stat-value">{{ $stats['pending_requests'] ?? 0 }}</div>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #fffbeb, #fef3c7); color: #f59e0b;">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <a href="{{ route('participations.index', ['status' => 'PENDING']) }}" class="text-decoration-none small text-warning fw-medium">
                        Έλεγχος <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card" style="--accent-color: #0ea5e9;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Ώρες Εθελοντισμού</div>
                        <div class="stat-value">{{ number_format($stats['total_hours'] ?? 0, 0) }}</div>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe); color: #0ea5e9;">
                        <i class="bi bi-stopwatch-fill"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <span class="small text-muted">
                        <i class="bi bi-graph-up-arrow text-success me-1"></i>
                        Συνολικές ώρες
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- ADMIN STATISTICS SECTION --}}
    @if(auth()->user()->isAdmin() && isset($adminStats))
    <div class="row g-4 mb-4">
        {{-- Left Column - Overview Cards --}}
        <div class="col-lg-8">
            {{-- Mission & Volunteer Overview --}}
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-graph-up me-2 text-primary"></i>Επισκόπηση Συστήματος
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        {{-- Missions Stats --}}
                        <div class="col-md-6">
                            <h6 class="text-muted mb-3"><i class="bi bi-flag me-2"></i>Αποστολές</h6>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-primary">{{ $adminStats['total_missions'] ?? 0 }}</div>
                                        <small class="text-muted">Σύνολο</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-success">{{ $adminStats['open_missions'] ?? 0 }}</div>
                                        <small class="text-muted">Ανοιχτές</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-info">{{ $adminStats['missions_this_month'] ?? 0 }}</div>
                                        <small class="text-muted">Αυτόν τον μήνα</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-secondary">{{ $adminStats['completed_missions'] ?? 0 }}</div>
                                        <small class="text-muted">Ολοκληρωμένες</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        {{-- Volunteers Stats --}}
                        <div class="col-md-6">
                            <h6 class="text-muted mb-3"><i class="bi bi-people me-2"></i>Εθελοντές</h6>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-primary">{{ $adminStats['total_volunteers'] ?? 0 }}</div>
                                        <small class="text-muted">Σύνολο</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-success">{{ $adminStats['active_volunteers'] ?? 0 }}</div>
                                        <small class="text-muted">Ενεργοί</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-info">{{ $adminStats['new_volunteers_this_month'] ?? 0 }}</div>
                                        <small class="text-muted">Νέοι (μήνα)</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-warning">{{ $adminStats['recently_active_volunteers'] ?? 0 }}</div>
                                        <small class="text-muted">Δραστήριοι (30η)</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Hours Statistics --}}
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-clock me-2 text-info"></i>Στατιστικά Ωρών
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-md-3">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="h3 mb-0 text-primary">{{ number_format($adminStats['hours_this_month'] ?? 0, 0) }}</div>
                                <small class="text-muted">Ώρες μήνα</small>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="h3 mb-0 text-success">{{ number_format($adminStats['hours_this_year'] ?? 0, 0) }}</div>
                                <small class="text-muted">Ώρες έτους</small>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="h3 mb-0 text-info">{{ $adminStats['avg_hours_per_volunteer'] ?? 0 }}</div>
                                <small class="text-muted">Μ.Ο./Εθελοντή</small>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="h3 mb-0 text-warning">{{ $adminStats['avg_participants_per_shift'] ?? 0 }}</div>
                                <small class="text-muted">Μ.Ο./Βάρδια</small>
                            </div>
                        </div>
                    </div>
                    
                    {{-- Hours by type --}}
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Εθελοντικές Ώρες</h6>
                            <div class="progress" style="height: 25px;">
                                @php
                                    $volHours = $adminStats['hours_by_type']['volunteer'] ?? 0;
                                    $medHours = $adminStats['hours_by_type']['medical'] ?? 0;
                                    $totalTypeHours = $volHours + $medHours;
                                    $volPercent = $totalTypeHours > 0 ? ($volHours / $totalTypeHours) * 100 : 0;
                                @endphp
                                <div class="progress-bar bg-success" style="width: {{ $volPercent }}%">
                                    {{ number_format($volHours, 0) }} ώρες
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Υγειονομικές Ώρες</h6>
                            <div class="progress" style="height: 25px;">
                                <div class="progress-bar bg-danger" style="width: {{ 100 - $volPercent }}%">
                                    {{ number_format($medHours, 0) }} ώρες
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Participation Statistics --}}
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-person-check me-2 text-success"></i>Στατιστικά Συμμετοχών
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <div class="text-center">
                                <div class="h2 mb-0">{{ $adminStats['total_participations'] ?? 0 }}</div>
                                <small class="text-muted">Σύνολο</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-center">
                                <div class="h2 mb-0 text-success">{{ $adminStats['approved_participations'] ?? 0 }}</div>
                                <small class="text-muted">Εγκεκριμένες</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-center">
                                <div class="h2 mb-0 text-warning">{{ $adminStats['pending_participations'] ?? 0 }}</div>
                                <small class="text-muted">Εκκρεμείς</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-center">
                                <div class="h2 mb-0 text-danger">{{ $adminStats['rejected_participations'] ?? 0 }}</div>
                                <small class="text-muted">Απορριφθείσες</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span>Ποσοστό Έγκρισης</span>
                            <strong class="text-success">{{ $adminStats['approval_rate'] ?? 0 }}%</strong>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar bg-success" style="width: {{ $adminStats['approval_rate'] ?? 0 }}%"></div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span>Ποσοστό Ενεργών Εθελοντών</span>
                            <strong class="text-info">{{ $adminStats['volunteer_activity_rate'] ?? 0 }}%</strong>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar bg-info" style="width: {{ $adminStats['volunteer_activity_rate'] ?? 0 }}%"></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Monthly Trends --}}
            @if(isset($adminStats['monthly_trends']) && count($adminStats['monthly_trends']) > 0)
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-graph-up-arrow me-2 text-primary"></i>Τάσεις 6μήνου
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Μήνας</th>
                                    <th class="text-center">Αποστολές</th>
                                    <th class="text-center">Συμμετοχές</th>
                                    <th class="text-center">Ώρες</th>
                                    <th class="text-center">Νέοι Εθελοντές</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($adminStats['monthly_trends'] as $trend)
                                <tr>
                                    <td><strong>{{ $trend['month'] }}</strong></td>
                                    <td class="text-center"><span class="badge bg-primary">{{ $trend['missions'] }}</span></td>
                                    <td class="text-center"><span class="badge bg-success">{{ $trend['participations'] }}</span></td>
                                    <td class="text-center"><span class="badge bg-info">{{ $trend['hours'] }}</span></td>
                                    <td class="text-center"><span class="badge bg-warning">{{ $trend['new_volunteers'] }}</span></td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif
        </div>

        {{-- Right Column - Additional Stats --}}
        <div class="col-lg-4">
            {{-- Shifts Overview --}}
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-calendar-event me-2 text-warning"></i>Βάρδιες
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Σήμερα</span>
                        <strong class="text-primary">{{ $adminStats['shifts_today'] ?? 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Αυτή την εβδομάδα</span>
                        <strong class="text-info">{{ $adminStats['shifts_this_week'] ?? 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Επερχόμενες</span>
                        <strong class="text-success">{{ $adminStats['upcoming_shifts'] ?? 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Σύνολο</span>
                        <strong>{{ $adminStats['total_shifts'] ?? 0 }}</strong>
                    </div>
                </div>
            </div>

            {{-- Top Departments --}}
            @if(isset($adminStats['top_departments']) && count($adminStats['top_departments']) > 0)
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-building me-2 text-success"></i>Top Τμήματα (Ώρες)
                </div>
                <div class="list-group list-group-flush">
                    @foreach($adminStats['top_departments'] as $index => $dept)
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            @if($index === 0)
                                <span class="badge bg-warning text-dark me-2">🥇</span>
                            @elseif($index === 1)
                                <span class="badge bg-secondary me-2">🥈</span>
                            @elseif($index === 2)
                                <span class="badge bg-danger me-2">🥉</span>
                            @else
                                <span class="badge bg-light text-dark me-2">{{ $index + 1 }}</span>
                            @endif
                            <span>{{ $dept['name'] }}</span>
                        </div>
                        <div class="text-end">
                            <strong>{{ $dept['hours'] }}</strong> <small class="text-muted">ώρες</small>
                            <br><small class="text-muted">{{ $dept['volunteers'] }} άτομα</small>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Volunteers by Department --}}
            @if(isset($adminStats['volunteers_by_department']) && count($adminStats['volunteers_by_department']) > 0)
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-pie-chart me-2 text-info"></i>Εθελοντές ανά Τμήμα
                </div>
                <div class="card-body">
                    @foreach($adminStats['volunteers_by_department'] as $dept)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>{{ $dept['department'] }}</span>
                        <span class="badge bg-primary">{{ $dept['count'] }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Comparison Cards --}}
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-arrow-left-right me-2 text-secondary"></i>Σύγκριση με Προηγ. Μήνα
                </div>
                <div class="card-body">
                    @php
                        $missionsDiff = ($adminStats['missions_this_month'] ?? 0) - ($adminStats['missions_last_month'] ?? 0);
                        $volunteersDiff = ($adminStats['new_volunteers_this_month'] ?? 0) - ($adminStats['new_volunteers_last_month'] ?? 0);
                        $hoursDiff = ($adminStats['hours_this_month'] ?? 0) - ($adminStats['hours_last_month'] ?? 0);
                    @endphp
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">Αποστολές</span>
                        <span class="badge {{ $missionsDiff >= 0 ? 'bg-success' : 'bg-danger' }}">
                            {{ $missionsDiff >= 0 ? '+' : '' }}{{ $missionsDiff }}
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">Νέοι Εθελοντές</span>
                        <span class="badge {{ $volunteersDiff >= 0 ? 'bg-success' : 'bg-danger' }}">
                            {{ $volunteersDiff >= 0 ? '+' : '' }}{{ $volunteersDiff }}
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted">Ώρες</span>
                        <span class="badge {{ $hoursDiff >= 0 ? 'bg-success' : 'bg-danger' }}">
                            {{ $hoursDiff >= 0 ? '+' : '' }}{{ number_format($hoursDiff, 0) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- PERSONAL STATISTICS SECTION (for all users) --}}
    @if(isset($personalStats))
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-person-badge me-2"></i>Τα Στατιστικά Μου
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        {{-- My Activity --}}
                        <div class="col-md-4">
                            <h6 class="text-muted mb-3"><i class="bi bi-activity me-2"></i>Δραστηριότητα</h6>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Συνολικές Συμμετοχές</span>
                                <strong>{{ $personalStats['total_participations'] ?? 0 }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Εγκεκριμένες</span>
                                <strong class="text-success">{{ $personalStats['approved_participations'] ?? 0 }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Εκκρεμείς</span>
                                <strong class="text-warning">{{ $personalStats['pending_participations'] ?? 0 }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Μοναδικές Αποστολές</span>
                                <strong class="text-info">{{ $personalStats['unique_missions'] ?? 0 }}</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Συνεχόμενοι Μήνες</span>
                                <strong class="text-primary">{{ $personalStats['streak'] ?? 0 }} 🔥</strong>
                            </div>
                        </div>

                        {{-- My Hours --}}
                        <div class="col-md-4">
                            <h6 class="text-muted mb-3"><i class="bi bi-clock me-2"></i>Ώρες Εθελοντισμού</h6>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Συνολικές</span>
                                <strong>{{ $personalStats['total_hours'] ?? 0 }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Αυτόν τον μήνα</span>
                                <strong class="text-primary">{{ $personalStats['hours_this_month'] ?? 0 }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Αυτό το έτος</span>
                                <strong class="text-info">{{ $personalStats['hours_this_year'] ?? 0 }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Εθελοντικές</span>
                                <strong class="text-success">{{ $personalStats['hours_by_type']['volunteer'] ?? 0 }}</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Υγειονομικές</span>
                                <strong class="text-danger">{{ $personalStats['hours_by_type']['medical'] ?? 0 }}</strong>
                            </div>
                        </div>

                        {{-- My Points & Ranking --}}
                        <div class="col-md-4">
                            <h6 class="text-muted mb-3"><i class="bi bi-trophy me-2"></i>Πόντοι & Κατάταξη</h6>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Συνολικοί Πόντοι</span>
                                <strong class="text-warning">{{ number_format($personalStats['total_points'] ?? 0) }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Μηνιαίοι Πόντοι</span>
                                <strong>{{ number_format($personalStats['monthly_points'] ?? 0) }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Κατάταξη (Πόντοι)</span>
                                <strong class="text-primary">
                                    #{{ $personalStats['ranking']['position'] ?? '-' }} / {{ $personalStats['ranking']['total'] ?? 0 }}
                                </strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Ανώτερος από</span>
                                <strong class="text-success">{{ $personalStats['ranking']['percentile'] ?? 0 }}%</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Επιτεύγματα</span>
                                <strong class="text-warning">{{ $personalStats['achievements_count'] ?? 0 }} 🏆</strong>
                            </div>
                        </div>
                    </div>

                    {{-- Member Info Row --}}
                    <div class="row mt-4 pt-3 border-top">
                        <div class="col-md-6">
                            <small class="text-muted">
                                <i class="bi bi-calendar-check me-1"></i>
                                Μέλος από: <strong>{{ $personalStats['member_since']?->format('d/m/Y') ?? '-' }}</strong>
                                ({{ $personalStats['days_as_member'] ?? 0 }} ημέρες)
                            </small>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <a href="{{ route('gamification.leaderboard') }}" class="btn btn-sm btn-outline-primary me-2">
                                <i class="bi bi-bar-chart me-1"></i>Κατάταξη
                            </a>
                            <a href="{{ route('gamification.achievements') }}" class="btn btn-sm btn-outline-warning">
                                <i class="bi bi-award me-1"></i>Επιτεύγματα
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- MAIN CONTENT ROW --}}
    <div class="row g-4">
        <!-- Recent Missions -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-flag me-2 text-primary"></i>Πρόσφατες Αποστολές</span>
                    <a href="{{ route('missions.index') }}" class="btn btn-sm btn-outline-primary">Όλες</a>
                </div>
                <div class="card-body p-0">
                    @if(isset($recentMissions) && count($recentMissions) > 0)
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Τίτλος</th>
                                        <th class="hide-mobile">Τύπος</th>
                                        <th>Κατάσταση</th>
                                        <th class="hide-mobile">Ημερομηνία</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentMissions as $mission)
                                        <tr>
                                            <td>
                                                <a href="{{ route('missions.show', $mission) }}">
                                                    {{ $mission->title }}
                                                </a>
                                            </td>
                                            <td class="hide-mobile">{{ $mission->mission_type ?? '-' }}</td>
                                            <td>
                                                <span class="badge badge-status badge-{{ strtolower($mission->status) }}">
                                                    {{ $mission->status_label ?? $mission->status }}
                                                </span>
                                            </td>
                                            <td class="hide-mobile">{{ $mission->created_at->format('d/m/Y') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-1"></i>
                            <p class="mt-2">Δεν υπάρχουν πρόσφατες αποστολές</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Gamification Widget -->
        <div class="col-lg-4">
            <!-- Your Points -->
            @if(auth()->user()->volunteerProfile)
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-trophy me-2"></i>Οι Πόντοι Σου
                </div>
                <div class="card-body text-center">
                    <div class="display-4 text-primary fw-bold mb-2">
                        {{ number_format(auth()->user()->total_points ?? 0) }}
                    </div>
                    <p class="text-muted mb-3">Συνολικοί Πόντοι</p>
                    
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="bg-light rounded p-2">
                                <small class="text-muted d-block">Μηνιαίοι</small>
                                <strong class="text-success">{{ number_format(auth()->user()->monthly_points ?? 0) }}</strong>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="bg-light rounded p-2">
                                <small class="text-muted d-block">Επιτεύγματα</small>
                                <strong class="text-warning">{{ auth()->user()->achievements()->count() }}</strong>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <a href="{{ route('gamification.leaderboard') }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-bar-chart me-1"></i>Κατάταξη
                        </a>
                        <a href="{{ route('gamification.achievements') }}" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-award me-1"></i>Επιτεύγματα
                        </a>
                    </div>
                </div>
            </div>
            @endif

            <!-- Recent Achievements -->
            @if(isset($recentAchievements) && count($recentAchievements) > 0)
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-award me-2"></i>Πρόσφατα Επιτεύγματα
                </div>
                <div class="list-group list-group-flush">
                    @foreach($recentAchievements as $achievement)
                        <div class="list-group-item d-flex align-items-center">
                            <span class="badge rounded-circle p-2 bg-{{ $achievement->color }} me-3">
                                <i class="{{ $achievement->icon }}"></i>
                            </span>
                            <div>
                                <strong>{{ $achievement->name }}</strong>
                                <small class="text-muted d-block">
                                    {{ $achievement->pivot->earned_at ? \Carbon\Carbon::parse($achievement->pivot->earned_at)->diffForHumans() : '' }}
                                </small>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            <!-- Top Volunteers This Month -->
            @if(isset($topVolunteers) && count($topVolunteers) > 0)
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-trophy text-warning me-2"></i>Top 5 Μηνός
                </div>
                <div class="list-group list-group-flush">
                    @foreach($topVolunteers as $index => $volunteer)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                @if($index === 0)
                                    <span class="badge bg-warning text-dark me-2">🥇</span>
                                @elseif($index === 1)
                                    <span class="badge bg-secondary me-2">🥈</span>
                                @elseif($index === 2)
                                    <span class="badge bg-danger me-2">🥉</span>
                                @else
                                    <span class="badge bg-light text-dark me-2">{{ $index + 1 }}</span>
                                @endif
                                <span>{{ $volunteer['name'] }}</span>
                            </div>
                            <span class="badge bg-primary">{{ number_format($volunteer['points']) }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="card-footer text-center bg-white">
                    <a href="{{ route('gamification.leaderboard', ['period' => 'monthly']) }}" class="text-decoration-none">
                        Δες την πλήρη κατάταξη →
                    </a>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
