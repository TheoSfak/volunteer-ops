@extends('layouts.app')

@section('title', 'Συμμετοχές')
@section('page-title', 'Αιτήσεις Συμμετοχής')

@section('content')
    <div class="card mb-4">
        <div class="card-body">
            <form action="{{ route('participations.index') }}" method="GET" class="row g-3">
                <div class="col-md-3">
                    <select class="form-select" name="status">
                        <option value="">Όλες οι καταστάσεις</option>
                        <option value="PENDING" {{ request('status') == 'PENDING' ? 'selected' : '' }}>Σε αναμονή</option>
                        <option value="APPROVED" {{ request('status') == 'APPROVED' ? 'selected' : '' }}>Εγκεκριμένες</option>
                        <option value="REJECTED" {{ request('status') == 'REJECTED' ? 'selected' : '' }}>Απορριφθείσες</option>
                        <option value="CANCELED" {{ request('status') == 'CANCELED' ? 'selected' : '' }}>Ακυρωμένες</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="mission_id">
                        <option value="">Όλες οι αποστολές</option>
                        @foreach($missions ?? [] as $mission)
                            <option value="{{ $mission->id }}" {{ request('mission_id') == $mission->id ? 'selected' : '' }}>{{ $mission->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="text" class="form-control datepicker" name="from_date" value="{{ request('from_date') }}" placeholder="Από (ημ/μηνία)">
                </div>
                <div class="col-md-2">
                    <input type="text" class="form-control datepicker" name="to_date" value="{{ request('to_date') }}" placeholder="Έως (ημ/μηνία)">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search"></i></button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card bg-warning bg-opacity-10 border-0">
                <div class="card-body text-center">
                    <div class="h3 text-warning mb-0">{{ $stats['pending'] ?? 0 }}</div>
                    <small class="text-muted">Σε αναμονή</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-success bg-opacity-10 border-0">
                <div class="card-body text-center">
                    <div class="h3 text-success mb-0">{{ $stats['approved'] ?? 0 }}</div>
                    <small class="text-muted">Εγκεκριμένες</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-danger bg-opacity-10 border-0">
                <div class="card-body text-center">
                    <div class="h3 text-danger mb-0">{{ $stats['rejected'] ?? 0 }}</div>
                    <small class="text-muted">Απορριφθείσες</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-secondary bg-opacity-10 border-0">
                <div class="card-body text-center">
                    <div class="h3 text-secondary mb-0">{{ $stats['cancelled'] ?? 0 }}</div>
                    <small class="text-muted">Ακυρωμένες</small>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Εθελοντής</th>
                            <th>Αποστολή / Βάρδια</th>
                            <th>Ημερομηνία Αίτησης</th>
                            <th>Κατάσταση</th>
                            <th class="text-end">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($participations ?? [] as $p)
                            <tr>
                                <td>
                                    <div class="fw-medium">{{ $p->volunteer->name ?? 'Άγνωστος' }}</div>
                                    <small class="text-muted">{{ $p->volunteer->email ?? '' }}</small>
                                </td>
                                <td>
                                    <div>{{ $p->shift->mission->title ?? '-' }}</div>
                                    <small class="text-muted">{{ $p->shift->start_time ? $p->shift->start_time->format('d/m/Y H:i') : '' }}</small>
                                </td>
                                <td>{{ $p->created_at ? $p->created_at->format('d/m/Y H:i') : '-' }}</td>
                                <td>
                                    @php
                                        $statusBadge = ['PENDING' => 'bg-warning text-dark', 'APPROVED' => 'bg-success', 'REJECTED' => 'bg-danger', 'CANCELED' => 'bg-secondary'];
                                        $statusLabel = ['PENDING' => 'Σε αναμονή', 'APPROVED' => 'Εγκεκριμένη', 'REJECTED' => 'Απορριφθείσα', 'CANCELED' => 'Ακυρωμένη'];
                                    @endphp
                                    <span class="badge {{ $statusBadge[$p->status] ?? 'bg-secondary' }}">{{ $statusLabel[$p->status] ?? $p->status }}</span>
                                </td>
                                <td class="text-end">
                                    @if($p->status === 'PENDING')
                                        <div class="btn-group btn-group-sm">
                                            <form action="{{ route('participations.approve', $p) }}" method="POST" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-success" title="Έγκριση"><i class="bi bi-check"></i></button>
                                            </form>
                                            <form action="{{ route('participations.reject', $p) }}" method="POST" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-danger" title="Απόρριψη"><i class="bi bi-x"></i></button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Δεν βρέθηκαν συμμετοχές</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if(isset($participations) && $participations->hasPages())
            <div class="card-footer">{{ $participations->links() }}</div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof flatpickr !== 'undefined') {
            flatpickr('.datepicker', {
                locale: 'gr',
                dateFormat: 'd/m/Y',
                allowInput: true
            });
        }
    });
</script>
@endpush
