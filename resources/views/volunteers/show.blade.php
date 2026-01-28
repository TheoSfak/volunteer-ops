@extends('layouts.app')

@section('title', $volunteer->name)
@section('page-title', $volunteer->name)

@section('content')
    <div class="row">
        {{-- Left Column - Profile & History --}}
        <div class="col-lg-8">
            {{-- Profile Card --}}
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-person me-2"></i>Προφίλ Εθελοντή</span>
                    @if(auth()->user()->isAdmin())
                    <a href="{{ route('volunteers.edit', $volunteer) }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil me-1"></i>Επεξεργασία
                    </a>
                    @endif
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Email</h6>
                            <p><i class="bi bi-envelope text-primary me-2"></i>{{ $volunteer->email }}</p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Τηλέφωνο</h6>
                            <p><i class="bi bi-phone text-primary me-2"></i>{{ $volunteer->phone ?? '-' }}</p>
                        </div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Τμήμα</h6>
                            <p>{{ $volunteer->department->name ?? '-' }}</p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Κατάσταση</h6>
                            <span class="badge {{ $volunteer->is_active ? 'bg-success' : 'bg-secondary' }}">
                                {{ $volunteer->is_active ? 'Ενεργός' : 'Ανενεργός' }}
                            </span>
                        </div>
                    </div>
                    @if($volunteer->volunteerProfile)
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">Ημ. Γέννησης</h6>
                                <p>{{ $volunteer->volunteerProfile->date_of_birth ? $volunteer->volunteerProfile->date_of_birth->format('d/m/Y') : '-' }}</p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">Ομάδα Αίματος</h6>
                                <p>{{ $volunteer->volunteerProfile->blood_type ?? '-' }}</p>
                            </div>
                        </div>
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">Διεύθυνση</h6>
                            <p>{{ $volunteer->volunteerProfile->address ?? '-' }}, {{ $volunteer->volunteerProfile->city ?? '' }} {{ $volunteer->volunteerProfile->postal_code ?? '' }}</p>
                        </div>
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">Επαφή Έκτακτης Ανάγκης</h6>
                            <p>{{ $volunteer->volunteerProfile->emergency_contact ?? '-' }}</p>
                        </div>
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">Ειδικότητες</h6>
                            <p>{{ $volunteer->volunteerProfile->specialties ?? '-' }}</p>
                        </div>
                    @endif
                    
                    {{-- Skills Section --}}
                    <div class="mt-4 pt-3 border-top">
                        <h6 class="text-muted mb-3"><i class="bi bi-award me-2"></i>Δεξιότητες & Διπλώματα</h6>
                        @php
                            $volunteerSkills = $volunteer->skills->groupBy('category');
                            $categoryLabels = \App\Models\Skill::CATEGORIES;
                        @endphp
                        
                        @if($volunteer->skills->isEmpty())
                            <p class="text-muted mb-0">Δεν έχει καταχωρηθεί καμία δεξιότητα.</p>
                        @else
                            @foreach($categoryLabels as $category => $label)
                                @if(isset($volunteerSkills[$category]) && $volunteerSkills[$category]->count() > 0)
                                    <div class="mb-2">
                                        <small class="text-muted">{{ $label }}:</small>
                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                            @foreach($volunteerSkills[$category] as $skill)
                                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary">
                                                    <i class="{{ $skill->icon ?? 'bi bi-check-circle' }} me-1"></i>
                                                    {{ $skill->name }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>

            {{-- Extended Statistics --}}
            @if(isset($stats))
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-graph-up me-2"></i>Αναλυτικά Στατιστικά
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        {{-- Participation Stats --}}
                        <div class="col-md-6">
                            <h6 class="text-muted mb-3"><i class="bi bi-person-check me-2"></i>Συμμετοχές</h6>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0">{{ $stats['total_participations'] ?? 0 }}</div>
                                        <small class="text-muted">Σύνολο</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-success">{{ $stats['approved_participations'] ?? 0 }}</div>
                                        <small class="text-muted">Εγκεκριμένες</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-warning">{{ $stats['pending_participations'] ?? 0 }}</div>
                                        <small class="text-muted">Εκκρεμείς</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-info">{{ $stats['unique_missions'] ?? 0 }}</div>
                                        <small class="text-muted">Αποστολές</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small>Ποσοστό Έγκρισης</small>
                                    <small class="text-success fw-bold">{{ $stats['approval_rate'] ?? 0 }}%</small>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-success" style="width: {{ $stats['approval_rate'] ?? 0 }}%"></div>
                                </div>
                            </div>
                        </div>

                        {{-- Hours Stats --}}
                        <div class="col-md-6">
                            <h6 class="text-muted mb-3"><i class="bi bi-clock me-2"></i>Ώρες Εθελοντισμού</h6>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-primary">{{ $stats['total_hours'] ?? 0 }}</div>
                                        <small class="text-muted">Συνολικές</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-info">{{ $stats['hours_this_month'] ?? 0 }}</div>
                                        <small class="text-muted">Αυτόν τον μήνα</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-success">{{ $stats['hours_by_type']['volunteer'] ?? 0 }}</div>
                                        <small class="text-muted">Εθελοντικές</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="h4 mb-0 text-danger">{{ $stats['hours_by_type']['medical'] ?? 0 }}</div>
                                        <small class="text-muted">Υγειονομικές</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <small class="text-muted">
                                    Μ.Ο. ανά συμμετοχή: <strong>{{ $stats['avg_hours_per_participation'] ?? 0 }}</strong> ώρες
                                </small>
                            </div>
                        </div>
                    </div>

                    {{-- Monthly Trend --}}
                    @if(isset($stats['monthly_trend']) && count($stats['monthly_trend']) > 0)
                    <div class="mt-4 pt-3 border-top">
                        <h6 class="text-muted mb-3"><i class="bi bi-bar-chart me-2"></i>Ώρες ανά Μήνα (τελευταίο 6μηνο)</h6>
                        <div class="row g-2">
                            @foreach($stats['monthly_trend'] as $month)
                            <div class="col-2 text-center">
                                <div class="bg-light rounded p-2">
                                    <div class="fw-bold text-primary">{{ $month['hours'] }}</div>
                                    <small class="text-muted">{{ $month['month'] }}</small>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    {{-- Activity Info --}}
                    <div class="row mt-4 pt-3 border-top">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-fire text-danger me-2 fs-5"></i>
                                <div>
                                    <small class="text-muted d-block">Σερί Μηνών</small>
                                    <strong>{{ $stats['streak'] ?? 0 }} συνεχόμενοι μήνες</strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-calendar-range text-info me-2 fs-5"></i>
                                <div>
                                    <small class="text-muted d-block">Ενεργοί Μήνες</small>
                                    <strong>{{ $stats['active_months'] ?? 0 }} μήνες</strong>
                                </div>
                            </div>
                        </div>
                        @if(isset($stats['busiest_month']) && $stats['busiest_month'])
                        <div class="col-md-6">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-trophy text-warning me-2 fs-5"></i>
                                <div>
                                    <small class="text-muted d-block">Πιο Δραστήριος Μήνας</small>
                                    <strong>{{ $stats['busiest_month']['month'] }}</strong>
                                    <small class="text-muted">({{ $stats['busiest_month']['hours'] }} ώρες)</small>
                                </div>
                            </div>
                        </div>
                        @endif
                        @if(isset($stats['last_participation_date']) && $stats['last_participation_date'])
                        <div class="col-md-6">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-clock-history text-secondary me-2 fs-5"></i>
                                <div>
                                    <small class="text-muted d-block">Τελευταία Συμμετοχή</small>
                                    <strong>{{ $stats['last_participation_date']->format('d/m/Y') }}</strong>
                                    @if($stats['days_since_last_activity'])
                                    <small class="text-muted">({{ $stats['days_since_last_activity'] }} ημέρες πριν)</small>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            @endif
            
            {{-- Participation History --}}
            <div class="card">
                <div class="card-header"><i class="bi bi-clock-history me-2"></i>Ιστορικό Συμμετοχών</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Αποστολή</th>
                                    <th class="hide-mobile">Βάρδια</th>
                                    <th class="hide-mobile">Ώρες</th>
                                    <th>Κατάσταση</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($volunteer->participationRequests ?? [] as $p)
                                    <tr>
                                        <td>
                                            @if($p->shift && $p->shift->mission)
                                                <a href="{{ route('missions.show', $p->shift->mission) }}">
                                                    {{ $p->shift->mission->title }}
                                                </a>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="hide-mobile">
                                            {{ $p->shift && $p->shift->start_time ? $p->shift->start_time->format('d/m/Y H:i') : '-' }}
                                        </td>
                                        <td class="hide-mobile">
                                            @if($p->shift && $p->shift->start_time && $p->shift->end_time)
                                                {{ round($p->shift->end_time->diffInMinutes($p->shift->start_time) / 60, 1) }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge {{ $p->status == 'APPROVED' ? 'bg-success' : ($p->status == 'PENDING' ? 'bg-warning' : ($p->status == 'REJECTED' ? 'bg-danger' : 'bg-secondary')) }}">
                                                @switch($p->status)
                                                    @case('APPROVED') Εγκεκριμένη @break
                                                    @case('PENDING') Εκκρεμεί @break
                                                    @case('REJECTED') Απορρίφθηκε @break
                                                    @default {{ $p->status }}
                                                @endswitch
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center py-4 text-muted">Δεν υπάρχουν συμμετοχές</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        {{-- Right Column - Stats & Achievements --}}
        <div class="col-lg-4">
            {{-- Quick Stats --}}
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-bar-chart me-2"></i>Σύνοψη</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Συνολικές Συμμετοχές</span>
                        <strong>{{ $stats['total_participations'] ?? $volunteer->participationRequests->count() ?? 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Εγκεκριμένες</span>
                        <strong class="text-success">{{ $stats['approved_participations'] ?? $volunteer->participationRequests->where('status', 'APPROVED')->count() ?? 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Συνολικές Ώρες</span>
                        <strong class="text-info">{{ $stats['total_hours'] ?? number_format($volunteer->total_volunteer_hours ?? 0, 1) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Ώρες Μήνα</span>
                        <strong class="text-primary">{{ $stats['hours_this_month'] ?? 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Ώρες Έτους</span>
                        <strong class="text-success">{{ $stats['hours_this_year'] ?? 0 }}</strong>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Συνολικοί Πόντοι</span>
                        <strong class="text-warning">{{ number_format($stats['total_points'] ?? $volunteer->total_points ?? 0) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Μηνιαίοι Πόντοι</span>
                        <strong>{{ number_format($stats['monthly_points'] ?? $volunteer->monthly_points ?? 0) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Μέλος από</span>
                        <strong>{{ $volunteer->created_at ? $volunteer->created_at->format('d/m/Y') : '-' }}</strong>
                    </div>
                </div>
            </div>

            {{-- Ranking Card --}}
            @if(isset($stats['ranking']))
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <i class="bi bi-trophy me-2"></i>Κατάταξη
                </div>
                <div class="card-body text-center">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="border rounded p-3">
                                <div class="h3 mb-0 text-primary">
                                    #{{ $stats['ranking']['points_position'] ?? '-' }}
                                </div>
                                <small class="text-muted">Πόντοι</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-3">
                                <div class="h3 mb-0 text-info">
                                    #{{ $stats['ranking']['hours_position'] ?? '-' }}
                                </div>
                                <small class="text-muted">Ώρες</small>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small>Ανώτερος από {{ $stats['ranking']['percentile'] ?? 0 }}% των εθελοντών</small>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-success" style="width: {{ $stats['ranking']['percentile'] ?? 0 }}%"></div>
                        </div>
                    </div>
                    <small class="text-muted mt-2 d-block">
                        Σύνολο εθελοντών: {{ $stats['ranking']['total_volunteers'] ?? 0 }}
                    </small>
                </div>
            </div>
            @endif

            {{-- Achievements --}}
            @if(isset($stats['achievements']) && $stats['achievements']->count() > 0)
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-trophy text-warning me-2"></i>Επιτεύγματα ({{ $stats['achievements_count'] ?? 0 }})</div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($stats['achievements']->take(8) as $achievement)
                            <span class="badge rounded-pill bg-{{ $achievement->color ?? 'primary' }} p-2" 
                                  title="{{ $achievement->description ?? '' }}"
                                  data-bs-toggle="tooltip">
                                <i class="{{ $achievement->icon ?? 'bi bi-award' }} me-1"></i>{{ $achievement->name }}
                            </span>
                        @endforeach
                    </div>
                    @if($stats['achievements']->count() > 8)
                        <small class="text-muted d-block mt-2">+{{ $stats['achievements']->count() - 8 }} ακόμη επιτεύγματα</small>
                    @endif
                </div>
            </div>
            @elseif($volunteer->achievements && $volunteer->achievements->count() > 0)
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-trophy text-warning me-2"></i>Επιτεύγματα</div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($volunteer->achievements->take(6) as $achievement)
                            <span class="badge rounded-pill bg-{{ $achievement->color ?? 'primary' }}" title="{{ $achievement->description ?? '' }}">
                                <i class="{{ $achievement->icon ?? 'bi bi-award' }} me-1"></i>{{ $achievement->name }}
                            </span>
                        @endforeach
                    </div>
                    @if($volunteer->achievements->count() > 6)
                        <small class="text-muted d-block mt-2">+{{ $volunteer->achievements->count() - 6 }} ακόμη επιτεύγματα</small>
                    @endif
                </div>
            </div>
            @endif

            {{-- Mission Type Preference --}}
            @if(isset($stats['mission_types']) && count($stats['mission_types']) > 0)
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-pie-chart me-2"></i>Τύπος Αποστολών</div>
                <div class="card-body">
                    @foreach($stats['mission_types'] as $type => $count)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>
                            @if($type === 'VOLUNTEER')
                                <i class="bi bi-heart text-success me-1"></i>Εθελοντικές
                            @else
                                <i class="bi bi-hospital text-danger me-1"></i>Υγειονομικές
                            @endif
                        </span>
                        <span class="badge bg-{{ $type === 'VOLUNTEER' ? 'success' : 'danger' }}">{{ $count }}</span>
                    </div>
                    @endforeach
                    @if(isset($stats['preferred_type']))
                    <hr>
                    <small class="text-muted">
                        Προτίμηση: <strong>{{ $stats['preferred_type'] === 'VOLUNTEER' ? 'Εθελοντικές' : 'Υγειονομικές' }}</strong>
                    </small>
                    @endif
                </div>
            </div>
            @endif

            {{-- Member Timeline --}}
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-calendar-event me-2"></i>Χρονολόγιο</div>
                <div class="card-body">
                    <div class="d-flex mb-3">
                        <div class="flex-shrink-0">
                            <div class="rounded-circle bg-success text-white p-2">
                                <i class="bi bi-person-plus"></i>
                            </div>
                        </div>
                        <div class="ms-3">
                            <strong>Εγγραφή</strong>
                            <p class="mb-0 text-muted small">{{ $volunteer->created_at ? $volunteer->created_at->format('d/m/Y') : '-' }}</p>
                        </div>
                    </div>
                    @if(isset($stats['first_participation_date']) && $stats['first_participation_date'])
                    <div class="d-flex mb-3">
                        <div class="flex-shrink-0">
                            <div class="rounded-circle bg-primary text-white p-2">
                                <i class="bi bi-flag"></i>
                            </div>
                        </div>
                        <div class="ms-3">
                            <strong>Πρώτη Συμμετοχή</strong>
                            <p class="mb-0 text-muted small">{{ $stats['first_participation_date']->format('d/m/Y') }}</p>
                        </div>
                    </div>
                    @endif
                    @if(isset($stats['last_participation_date']) && $stats['last_participation_date'])
                    <div class="d-flex">
                        <div class="flex-shrink-0">
                            <div class="rounded-circle bg-info text-white p-2">
                                <i class="bi bi-clock-history"></i>
                            </div>
                        </div>
                        <div class="ms-3">
                            <strong>Τελευταία Συμμετοχή</strong>
                            <p class="mb-0 text-muted small">{{ $stats['last_participation_date']->format('d/m/Y') }}</p>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
            
            {{-- Actions --}}
            <div class="card">
                <div class="card-header"><i class="bi bi-lightning me-2"></i>Ενέργειες</div>
                <div class="card-body d-grid gap-2">
                    @if(auth()->user()->isAdmin())
                    <a href="{{ route('volunteers.edit', $volunteer) }}" class="btn btn-outline-primary">
                        <i class="bi bi-pencil me-2"></i>Επεξεργασία
                    </a>
                    @endif
                    <a href="{{ route('volunteers.index') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Πίσω
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    })
</script>
@endpush
