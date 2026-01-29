@extends('layouts.app')

@section('title', 'Διαχείριση Παρουσιών - ' . $mission->title)

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            {{-- Breadcrumb --}}
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('missions.index') }}">Αποστολές</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('missions.show', $mission) }}">{{ Str::limit($mission->title, 30) }}</a></li>
                    <li class="breadcrumb-item active">Διαχείριση Παρουσιών</li>
                </ol>
            </nav>

            {{-- Header --}}
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="bi bi-clipboard-check me-2"></i>Διαχείριση Παρουσιών
                    </h1>
                    <p class="text-muted mb-0">{{ $mission->title }}</p>
                </div>
                <div class="d-flex gap-2">
                    <form action="{{ route('attendance.mark-all', $mission) }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-success" 
                                onclick="return confirm('Θέλετε να επιβεβαιώσετε όλους τους εθελοντές ως παρόντες;')">
                            <i class="bi bi-check-all me-1"></i>Επιβεβαίωση Όλων
                        </button>
                    </form>
                    <form action="{{ route('missions.complete', $mission) }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-success" 
                                onclick="return confirm('Μόλις ολοκληρωθεί η αποστολή, οι πόντοι θα αποδοθούν στους εθελοντές. Συνέχεια;')">
                            <i class="bi bi-check-circle me-1"></i>Ολοκλήρωση Αποστολής
                        </button>
                    </form>
                </div>
            </div>

            {{-- Mission Info Card --}}
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <small class="text-muted d-block">Τμήμα</small>
                            <strong>{{ $mission->department->name ?? '-' }}</strong>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted d-block">Ημερομηνία</small>
                            <strong>{{ $mission->start_datetime->format('d/m/Y') }}</strong>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted d-block">Ώρες</small>
                            <strong>{{ $mission->start_datetime->format('H:i') }} - {{ $mission->end_datetime->format('H:i') }}</strong>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted d-block">Κατάσταση</small>
                            <span class="badge bg-warning">{{ $mission->status_label }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Alert Info --}}
            <div class="alert alert-info mb-4">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Οδηγίες:</strong> Επιβεβαιώστε την παρουσία κάθε εθελοντή και διορθώστε τις ώρες αν χρειάζεται. 
                Όταν ολοκληρωθεί, οι πόντοι θα αποδοθούν αυτόματα βάσει των πραγματικών ωρών.
            </div>

            {{-- Shifts & Participations --}}
            @foreach($mission->shifts as $shift)
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-clock me-2"></i>{{ $shift->title ?? 'Βάρδια' }}
                        </h5>
                        <span class="badge bg-secondary">
                            {{ \Carbon\Carbon::parse($shift->start_time)->format('H:i') }} - 
                            {{ \Carbon\Carbon::parse($shift->end_time)->format('H:i') }}
                            ({{ round(\Carbon\Carbon::parse($shift->start_time)->diffInMinutes(\Carbon\Carbon::parse($shift->end_time)) / 60, 1) }} ώρες)
                        </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    @if($shift->participations->isEmpty())
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-person-x fs-1 d-block mb-2"></i>
                            Δεν υπάρχουν εγκεκριμένοι εθελοντές σε αυτή τη βάρδια.
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 250px;">Εθελοντής</th>
                                        <th style="width: 120px;" class="text-center">Παρουσία</th>
                                        <th style="width: 200px;">Πραγματικές Ώρες</th>
                                        <th>Σημειώσεις</th>
                                        <th style="width: 80px;" class="text-center">Ενέργειες</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($shift->participations as $participation)
                                    <tr id="row-{{ $participation->id }}" class="{{ $participation->attendance_confirmed_at ? 'table-success' : '' }}">
                                        <form action="{{ route('attendance.update', $participation) }}" method="POST" class="attendance-form">
                                            @csrf
                                            @method('PUT')
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-sm me-2">
                                                        <span class="avatar-title rounded-circle bg-primary text-white">
                                                            {{ substr($participation->volunteer->name ?? 'U', 0, 1) }}
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <strong>{{ $participation->volunteer->name ?? 'Άγνωστος' }}</strong>
                                                        <small class="d-block text-muted">{{ $participation->volunteer->email ?? '' }}</small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group" role="group">
                                                    <input type="radio" class="btn-check attended-radio" 
                                                           name="attended" id="attended-yes-{{ $participation->id }}" 
                                                           value="1" {{ $participation->attended ? 'checked' : '' }}>
                                                    <label class="btn btn-outline-success btn-sm" for="attended-yes-{{ $participation->id }}">
                                                        <i class="bi bi-check"></i> Ήρθε
                                                    </label>
                                                    
                                                    <input type="radio" class="btn-check attended-radio" 
                                                           name="attended" id="attended-no-{{ $participation->id }}" 
                                                           value="0" {{ !$participation->attended ? 'checked' : '' }}>
                                                    <label class="btn btn-outline-danger btn-sm" for="attended-no-{{ $participation->id }}">
                                                        <i class="bi bi-x"></i> No-Show
                                                    </label>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="hours-section">
                                                    <select name="hours_type" class="form-select form-select-sm hours-type mb-1">
                                                        <option value="shift" {{ !$participation->actual_hours && !$participation->actual_start_time ? 'selected' : '' }}>
                                                            Ώρες βάρδιας ({{ round(\Carbon\Carbon::parse($shift->start_time)->diffInMinutes(\Carbon\Carbon::parse($shift->end_time)) / 60, 1) }}ώρ)
                                                        </option>
                                                        <option value="custom_hours" {{ $participation->actual_hours ? 'selected' : '' }}>
                                                            Συνολικές ώρες
                                                        </option>
                                                        <option value="custom_time" {{ $participation->actual_start_time ? 'selected' : '' }}>
                                                            Ώρες έναρξης/λήξης
                                                        </option>
                                                    </select>
                                                    <div class="custom-hours-input" style="{{ $participation->actual_hours ? '' : 'display:none;' }}">
                                                        <input type="number" name="actual_hours" class="form-control form-control-sm" 
                                                               placeholder="π.χ. 4.5" step="0.5" min="0" max="24"
                                                               value="{{ $participation->actual_hours }}">
                                                    </div>
                                                    <div class="custom-time-input" style="{{ $participation->actual_start_time ? '' : 'display:none;' }}">
                                                        <div class="input-group input-group-sm">
                                                            <input type="time" name="actual_start_time" class="form-control form-control-sm"
                                                                   value="{{ $participation->actual_start_time ? \Carbon\Carbon::parse($participation->actual_start_time)->format('H:i') : '' }}">
                                                            <span class="input-group-text">-</span>
                                                            <input type="time" name="actual_end_time" class="form-control form-control-sm"
                                                                   value="{{ $participation->actual_end_time ? \Carbon\Carbon::parse($participation->actual_end_time)->format('H:i') : '' }}">
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" name="admin_notes" class="form-control form-control-sm" 
                                                       placeholder="Σημειώσεις..." value="{{ $participation->admin_notes }}">
                                            </td>
                                            <td class="text-center">
                                                <button type="submit" class="btn btn-primary btn-sm">
                                                    <i class="bi bi-save"></i>
                                                </button>
                                            </td>
                                        </form>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
            @endforeach

            {{-- Summary --}}
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Σύνοψη</h5>
                </div>
                <div class="card-body">
                    @php
                        $totalParticipations = $mission->shifts->sum(fn($s) => $s->participations->count());
                        $confirmedCount = $mission->shifts->sum(fn($s) => $s->participations->whereNotNull('attendance_confirmed_at')->count());
                        $attendedCount = $mission->shifts->sum(fn($s) => $s->participations->where('attended', true)->whereNotNull('attendance_confirmed_at')->count());
                        $noShowCount = $mission->shifts->sum(fn($s) => $s->participations->where('attended', false)->whereNotNull('attendance_confirmed_at')->count());
                    @endphp
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h3 class="mb-0">{{ $totalParticipations }}</h3>
                            <small class="text-muted">Συνολικές Συμμετοχές</small>
                        </div>
                        <div class="col-md-3">
                            <h3 class="mb-0 text-success">{{ $confirmedCount }}</h3>
                            <small class="text-muted">Επιβεβαιωμένες</small>
                        </div>
                        <div class="col-md-3">
                            <h3 class="mb-0 text-primary">{{ $attendedCount }}</h3>
                            <small class="text-muted">Παρόντες</small>
                        </div>
                        <div class="col-md-3">
                            <h3 class="mb-0 text-danger">{{ $noShowCount }}</h3>
                            <small class="text-muted">No-Shows</small>
                        </div>
                    </div>
                    
                    @if($confirmedCount < $totalParticipations)
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Εκκρεμεί η επιβεβαίωση για {{ $totalParticipations - $confirmedCount }} εθελοντές.
                    </div>
                    @else
                    <div class="alert alert-success mt-3 mb-0">
                        <i class="bi bi-check-circle me-2"></i>
                        Όλες οι παρουσίες έχουν επιβεβαιωθεί. Μπορείτε να ολοκληρώσετε την αποστολή.
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.avatar-sm {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.avatar-title {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    font-weight: bold;
}
.hours-section {
    min-width: 180px;
}
</style>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle hours input based on type
    document.querySelectorAll('.hours-type').forEach(function(select) {
        select.addEventListener('change', function() {
            const row = this.closest('tr');
            const customHours = row.querySelector('.custom-hours-input');
            const customTime = row.querySelector('.custom-time-input');
            
            customHours.style.display = 'none';
            customTime.style.display = 'none';
            
            if (this.value === 'custom_hours') {
                customHours.style.display = 'block';
            } else if (this.value === 'custom_time') {
                customTime.style.display = 'block';
            }
        });
    });
    
    // Disable hours section when no-show
    document.querySelectorAll('.attended-radio').forEach(function(radio) {
        radio.addEventListener('change', function() {
            const row = this.closest('tr');
            const hoursSection = row.querySelector('.hours-section');
            
            if (this.value === '0') {
                hoursSection.style.opacity = '0.5';
                hoursSection.querySelectorAll('input, select').forEach(el => el.disabled = true);
            } else {
                hoursSection.style.opacity = '1';
                hoursSection.querySelectorAll('input, select').forEach(el => el.disabled = false);
            }
        });
        
        // Initial state
        if (radio.checked && radio.value === '0') {
            radio.dispatchEvent(new Event('change'));
        }
    });
});
</script>
@endpush
@endsection
