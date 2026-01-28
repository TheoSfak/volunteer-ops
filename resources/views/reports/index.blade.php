@extends('layouts.app')

@section('title', 'Αναφορές')
@section('page-title', 'Αναφορές & Στατιστικά')

@section('content')
    <!-- Summary Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="h2 mb-0">{{ $stats['total_missions'] ?? 0 }}</div>
                            <div>Συνολικές Αποστολές</div>
                        </div>
                        <i class="bi bi-flag fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="h2 mb-0">{{ $stats['total_shifts'] ?? 0 }}</div>
                            <div>Συνολικές Βάρδιες</div>
                        </div>
                        <i class="bi bi-calendar-event fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="h2 mb-0">{{ $stats['total_volunteers'] ?? 0 }}</div>
                            <div>Εθελοντές</div>
                        </div>
                        <i class="bi bi-people fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="h2 mb-0">{{ $stats['total_hours'] ?? 0 }}</div>
                            <div>Ώρες Εθελοντισμού</div>
                        </div>
                        <i class="bi bi-clock-history fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Missions by Status -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-pie-chart me-2"></i>Αποστολές ανά Κατάσταση</div>
                <div class="card-body">
                    @php
                        $missionStats = $stats['missions_by_status'] ?? collect();
                        $statusLabels = [
                            'DRAFT' => 'Πρόχειρες', 
                            'OPEN' => 'Ανοιχτές', 
                            'CLOSED' => 'Κλειστές',
                            'COMPLETED' => 'Ολοκληρωμένες', 
                            'CANCELED' => 'Ακυρωμένες'
                        ];
                        $statusColors = [
                            'DRAFT' => 'secondary', 
                            'OPEN' => 'primary', 
                            'CLOSED' => 'warning',
                            'COMPLETED' => 'success', 
                            'CANCELED' => 'danger'
                        ];
                    @endphp
                    @forelse($missionStats as $status => $count)
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-{{ $statusColors[$status] ?? 'secondary' }}">{{ $count }}</span>
                                <span>{{ $statusLabels[$status] ?? $status }}</span>
                            </div>
                            <div class="progress flex-grow-1 mx-3" style="height: 8px;">
                                @php $percent = ($stats['total_missions'] ?? 1) > 0 ? ($count / ($stats['total_missions'] ?? 1)) * 100 : 0; @endphp
                                <div class="progress-bar bg-{{ $statusColors[$status] ?? 'secondary' }}" style="width: {{ $percent }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted text-center">Δεν υπάρχουν δεδομένα</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Top Departments -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-building me-2"></i>Τμήματα με τις Περισσότερες Αποστολές</div>
                <div class="card-body">
                    @forelse($stats['top_departments'] ?? [] as $dept)
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span>{{ $dept->name }}</span>
                            <span class="badge bg-primary">{{ $dept->missions_count }}</span>
                        </div>
                    @empty
                        <p class="text-muted text-center">Δεν υπάρχουν δεδομένα</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-activity me-2"></i>Πρόσφατη Δραστηριότητα</div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @forelse($stats['recent_activity'] ?? [] as $activity)
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <span>{{ $activity->description ?? 'Δραστηριότητα' }}</span>
                                    <small class="text-muted">{{ $activity->created_at ? $activity->created_at->diffForHumans() : '' }}</small>
                                </div>
                            </div>
                        @empty
                            <div class="list-group-item text-center text-muted py-4">Δεν υπάρχει δραστηριότητα</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Volunteers -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-trophy me-2"></i>Κορυφαίοι Εθελοντές</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr><th>Εθελοντής</th><th class="text-end">Συμμετοχές</th></tr>
                            </thead>
                            <tbody>
                                @forelse($stats['top_volunteers'] ?? [] as $vol)
                                    <tr>
                                        <td>{{ $vol->name }}</td>
                                        <td class="text-end"><span class="badge bg-success">{{ $vol->participation_requests_count }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="2" class="text-center py-4 text-muted">Δεν υπάρχουν δεδομένα</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Export -->
    <div class="card mt-4">
        <div class="card-header"><i class="bi bi-download me-2"></i>Εξαγωγή Αναφορών</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <a href="{{ route('reports.export', ['type' => 'missions']) }}" class="btn btn-outline-primary w-100">
                        <i class="bi bi-file-earmark-excel me-2"></i>Εξαγωγή Αποστολών
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="{{ route('reports.export', ['type' => 'volunteers']) }}" class="btn btn-outline-primary w-100">
                        <i class="bi bi-file-earmark-excel me-2"></i>Εξαγωγή Εθελοντών
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="{{ route('reports.export', ['type' => 'participations']) }}" class="btn btn-outline-primary w-100">
                        <i class="bi bi-file-earmark-excel me-2"></i>Εξαγωγή Συμμετοχών
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="{{ route('reports.export', ['type' => 'hours']) }}" class="btn btn-outline-primary w-100">
                        <i class="bi bi-file-earmark-excel me-2"></i>Εξαγωγή Ωρών
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
