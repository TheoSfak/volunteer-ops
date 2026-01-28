@extends('layouts.app')

@section('title', 'Βάρδιες')
@section('page-title', 'Βάρδιες')

@section('content')
    <!-- Header Actions -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <p class="text-muted mb-0">Διαχείριση βαρδιών εθελοντών</p>
        </div>
        @if(auth()->user()->isAdmin())
        <a href="{{ route('shifts.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-2"></i>Νέα Βάρδια
        </a>
        @endif
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form action="{{ route('shifts.index') }}" method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Αποστολή</label>
                    <select class="form-select" name="mission_id">
                        <option value="">Όλες</option>
                        @foreach($missions ?? [] as $mission)
                            <option value="{{ $mission->id }}" {{ request('mission_id') == $mission->id ? 'selected' : '' }}>
                                {{ $mission->title }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Από</label>
                    <input type="date" class="form-control" name="from_date" value="{{ request('from_date') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Έως</label>
                    <input type="date" class="form-control" name="to_date" value="{{ request('to_date') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Κατάσταση</label>
                    <select class="form-select" name="status">
                        <option value="">Όλες</option>
                        <option value="upcoming" {{ request('status') == 'upcoming' ? 'selected' : '' }}>Επερχόμενες</option>
                        <option value="ongoing" {{ request('status') == 'ongoing' ? 'selected' : '' }}>Σε εξέλιξη</option>
                        <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Ολοκληρωμένες</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Διαθεσιμότητα</label>
                    <select class="form-select" name="availability">
                        <option value="">Όλες</option>
                        <option value="available" {{ request('availability') == 'available' ? 'selected' : '' }}>Με θέσεις</option>
                        <option value="full" {{ request('availability') == 'full' ? 'selected' : '' }}>Πλήρεις</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Shifts List -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Αποστολή</th>
                            <th>Ημερομηνία</th>
                            <th>Ώρες</th>
                            <th>Υπεύθυνος</th>
                            <th>Συμμετέχοντες</th>
                            <th>Κατάσταση</th>
                            <th class="text-end">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($shifts ?? [] as $shift)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="bg-success bg-opacity-10 rounded p-2">
                                            <i class="bi bi-calendar-event text-success"></i>
                                        </div>
                                        <div>
                                            <div class="fw-medium">{{ $shift->mission->title ?? 'Χωρίς αποστολή' }}</div>
                                            <small class="text-muted">{{ $shift->mission->location ?? '' }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    {{ $shift->start_time ? $shift->start_time->format('d/m/Y') : '-' }}
                                </td>
                                <td>
                                    <i class="bi bi-clock text-muted me-1"></i>
                                    {{ $shift->start_time ? $shift->start_time->format('H:i') : '' }} - 
                                    {{ $shift->end_time ? $shift->end_time->format('H:i') : '' }}
                                </td>
                                <td>{{ $shift->leader->name ?? '-' }}</td>
                                <td>
                                    @php
                                        $count = $shift->participations_count ?? $shift->participations->count() ?? 0;
                                        $max = $shift->max_capacity ?? 1;
                                        $percent = min(100, ($count / $max) * 100);
                                    @endphp
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress" style="width: 80px; height: 8px;">
                                            <div class="progress-bar {{ $percent >= 100 ? 'bg-success' : ($percent >= 50 ? 'bg-primary' : 'bg-warning') }}" 
                                                 style="width: {{ $percent }}%"></div>
                                        </div>
                                        <small class="text-muted">{{ $count }}/{{ $max }}</small>
                                    </div>
                                </td>
                                <td>
                                    @php
                                        $now = now();
                                        $start = $shift->start_time;
                                        $end = $shift->end_time;
                                        
                                        if (!$start) {
                                            $shiftStatus = 'unknown';
                                            $shiftStatusClass = 'bg-secondary';
                                            $shiftStatusLabel = 'Άγνωστη';
                                        } elseif ($now < $start) {
                                            $shiftStatus = 'upcoming';
                                            $shiftStatusClass = 'bg-info';
                                            $shiftStatusLabel = 'Επερχόμενη';
                                        } elseif ($end && $now > $end) {
                                            $shiftStatus = 'completed';
                                            $shiftStatusClass = 'bg-secondary';
                                            $shiftStatusLabel = 'Ολοκληρωμένη';
                                        } else {
                                            $shiftStatus = 'ongoing';
                                            $shiftStatusClass = 'bg-success';
                                            $shiftStatusLabel = 'Σε εξέλιξη';
                                        }
                                    @endphp
                                    <span class="badge {{ $shiftStatusClass }}">{{ $shiftStatusLabel }}</span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('shifts.show', $shift) }}" class="btn btn-outline-primary" title="Προβολή">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        @if(auth()->user()->isAdmin())
                                        <a href="{{ route('shifts.edit', $shift) }}" class="btn btn-outline-secondary" title="Επεξεργασία">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" title="Διαγραφή" 
                                                onclick="confirmDelete({{ $shift->id }})">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        @endif
                                    </div>
                                    @if(auth()->user()->isAdmin())
                                    <form id="delete-form-{{ $shift->id }}" action="{{ route('shifts.destroy', $shift) }}" method="POST" class="d-none">
                                        @csrf
                                        @method('DELETE')
                                    </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="bi bi-calendar-x fs-1 d-block mb-3"></i>
                                        <p class="mb-3">Δεν βρέθηκαν βάρδιες</p>
                                        @if(auth()->user()->isAdmin())
                                        <a href="{{ route('shifts.create') }}" class="btn btn-primary">
                                            <i class="bi bi-plus-lg me-2"></i>Δημιουργία Βάρδιας
                                        </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        
        @if(isset($shifts) && $shifts->hasPages())
            <div class="card-footer">
                {{ $shifts->links() }}
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    function confirmDelete(id) {
        if (confirm('Είστε σίγουροι ότι θέλετε να διαγράψετε αυτή τη βάρδια;')) {
            document.getElementById('delete-form-' + id).submit();
        }
    }
</script>
@endpush
