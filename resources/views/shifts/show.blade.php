@extends('layouts.app')

@section('title', 'Βάρδια: ' . ($shift->mission->title ?? 'Χωρίς αποστολή'))
@section('page-title', 'Λεπτομέρειες Βάρδιας')

@section('content')
    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- Shift Info Card -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-event me-2"></i>Πληροφορίες Βάρδιας</span>
                    @if(auth()->user()->isAdmin())
                    <div class="btn-group btn-group-sm">
                        <a href="{{ route('shifts.edit', $shift) }}" class="btn btn-outline-primary">
                            <i class="bi bi-pencil me-1"></i>Επεξεργασία
                        </a>
                        <button type="button" class="btn btn-outline-danger" onclick="confirmDelete()">
                            <i class="bi bi-trash me-1"></i>Διαγραφή
                        </button>
                    </div>
                    @endif
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Αποστολή</h6>
                            <a href="{{ route('missions.show', $shift->mission_id) }}" class="text-decoration-none">
                                <i class="bi bi-flag text-primary me-2"></i>
                                {{ $shift->mission->title ?? 'Χωρίς αποστολή' }}
                            </a>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Κατάσταση</h6>
                            @php
                                $now = now();
                                $start = $shift->start_time;
                                $end = $shift->end_time;
                                
                                if (!$start) {
                                    $shiftStatusClass = 'bg-secondary';
                                    $shiftStatusLabel = 'Άγνωστη';
                                } elseif ($now < $start) {
                                    $shiftStatusClass = 'bg-info';
                                    $shiftStatusLabel = 'Επερχόμενη';
                                } elseif ($end && $now > $end) {
                                    $shiftStatusClass = 'bg-secondary';
                                    $shiftStatusLabel = 'Ολοκληρωμένη';
                                } else {
                                    $shiftStatusClass = 'bg-success';
                                    $shiftStatusLabel = 'Σε εξέλιξη';
                                }
                            @endphp
                            <span class="badge {{ $shiftStatusClass }}">{{ $shiftStatusLabel }}</span>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Ώρα Έναρξης</h6>
                            <p class="mb-0">
                                <i class="bi bi-clock text-primary me-2"></i>
                                {{ $shift->start_time ? $shift->start_time->format('d/m/Y H:i') : '-' }}
                            </p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Ώρα Λήξης</h6>
                            <p class="mb-0">
                                <i class="bi bi-clock-fill text-primary me-2"></i>
                                {{ $shift->end_time ? $shift->end_time->format('d/m/Y H:i') : '-' }}
                            </p>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Υπεύθυνος Βάρδιας</h6>
                            <p class="mb-0">
                                <i class="bi bi-person-badge text-primary me-2"></i>
                                {{ $shift->leader->name ?? 'Χωρίς υπεύθυνο' }}
                            </p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Χωρητικότητα</h6>
                            @php
                                $count = $shift->participations->where('status', 'approved')->count() ?? 0;
                                $max = $shift->max_capacity ?? 1;
                                $percent = min(100, ($count / $max) * 100);
                            @endphp
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height: 10px;">
                                    <div class="progress-bar {{ $percent >= 100 ? 'bg-success' : 'bg-primary' }}" 
                                         style="width: {{ $percent }}%"></div>
                                </div>
                                <span class="fw-medium">{{ $count }}/{{ $max }}</span>
                            </div>
                        </div>
                    </div>
                    
                    @if($shift->notes)
                        <div class="mb-0">
                            <h6 class="text-muted mb-2">Σημειώσεις</h6>
                            <p class="mb-0">{{ $shift->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>
            
            <!-- Participants Card -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-2"></i>Συμμετέχοντες</span>
                    <div>
                        @if($shift->participations->where('status', 'PENDING')->count() > 0)
                            <span class="badge bg-warning text-dark me-1">{{ $shift->participations->where('status', 'PENDING')->count() }} σε αναμονή</span>
                        @endif
                        <span class="badge bg-primary">{{ $shift->participations->where('status', 'APPROVED')->count() }} εγκεκριμένοι</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Εθελοντής</th>
                                    <th>Τηλέφωνο</th>
                                    <th>Κατάσταση</th>
                                    @if(auth()->user()->isAdmin())
                                    <th></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($shift->participations->whereIn('status', ['PENDING', 'APPROVED']) as $participation)
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="bg-primary bg-opacity-10 rounded-circle p-2">
                                                    <i class="bi bi-person text-primary"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-medium">{{ $participation->volunteer->name ?? 'Άγνωστος' }}</div>
                                                    <small class="text-muted">{{ $participation->volunteer->email ?? '' }}</small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>{{ $participation->volunteer->phone ?? '-' }}</td>
                                        <td>
                                            @if($participation->status === 'PENDING')
                                                <span class="badge bg-warning text-dark">Σε Αναμονή</span>
                                            @else
                                                <span class="badge bg-success">Εγκεκριμένος</span>
                                            @endif
                                        </td>
                                        @if(auth()->user()->isAdmin())
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                @if($participation->status === 'PENDING')
                                                    <form action="{{ route('participations.approve', $participation) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-success btn-sm" title="Έγκριση">
                                                            <i class="bi bi-check"></i>
                                                        </button>
                                                    </form>
                                                    <form action="{{ route('participations.reject', $participation) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-danger btn-sm" title="Απόρριψη">
                                                            <i class="bi bi-x"></i>
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ auth()->user()->isAdmin() ? 4 : 3 }}" class="text-center py-4 text-muted">
                                            Δεν υπάρχουν συμμετέχοντες
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Quick Stats -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-bar-chart me-2"></i>Στατιστικά
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">Εγκεκριμένοι</span>
                        <strong class="text-success">{{ $shift->participations->where('status', 'approved')->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">Σε αναμονή</span>
                        <strong class="text-warning">{{ $shift->participations->where('status', 'pending')->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">Check-ins</span>
                        <strong>{{ $shift->participations->whereNotNull('check_in_at')->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted">Διαθέσιμες θέσεις</span>
                        <strong>{{ max(0, $shift->max_capacity - $shift->participations->where('status', 'approved')->count()) }}</strong>
                    </div>
                </div>
            </div>
            
            <!-- Duration -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-stopwatch me-2"></i>Διάρκεια
                </div>
                <div class="card-body text-center">
                    @if($shift->start_time && $shift->end_time)
                        @php
                            $duration = $shift->start_time->diff($shift->end_time);
                            $hours = $duration->h + ($duration->days * 24);
                            $minutes = $duration->i;
                        @endphp
                        <div class="display-6 fw-bold text-primary">{{ $hours }}:{{ str_pad($minutes, 2, '0', STR_PAD_LEFT) }}</div>
                        <div class="text-muted">ώρες</div>
                    @else
                        <div class="text-muted">Δεν έχει οριστεί</div>
                    @endif
                </div>
            </div>
            
            <!-- Actions -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-lightning me-2"></i>Ενέργειες
                </div>
                <div class="card-body d-grid gap-2">
                    @php
                        $user = auth()->user();
                        $hasApplied = $shift->participations->where('volunteer_id', $user->id)->whereIn('status', ['PENDING', 'APPROVED'])->first();
                        $isApproved = $shift->participations->where('volunteer_id', $user->id)->where('status', 'APPROVED')->first();
                        $isPending = $shift->participations->where('volunteer_id', $user->id)->where('status', 'PENDING')->first();
                        $isFull = $shift->max_capacity && $shift->participations->where('status', 'APPROVED')->count() >= $shift->max_capacity;
                        $hasStarted = $shift->start_time && $shift->start_time < now();
                    @endphp
                    
                    @if($isApproved)
                        <!-- Ο εθελοντής είναι εγκεκριμένος -->
                        <div class="alert alert-success mb-2">
                            <i class="bi bi-check-circle me-2"></i>
                            <strong>Συμμετέχετε</strong> σε αυτή τη βάρδια
                        </div>
                    @elseif($isPending)
                        <!-- Η αίτηση είναι σε αναμονή -->
                        <div class="alert alert-warning mb-2">
                            <i class="bi bi-hourglass-split me-2"></i>
                            Η αίτησή σας είναι <strong>σε αναμονή</strong>
                        </div>
                        <form action="{{ route('participations.cancel', $isPending) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100" 
                                    onclick="return confirm('Θέλετε να ακυρώσετε την αίτησή σας;')">
                                <i class="bi bi-x-circle me-2"></i>Ακύρωση Αίτησης
                            </button>
                        </form>
                    @elseif($hasStarted)
                        <!-- Η βάρδια έχει ξεκινήσει -->
                        <div class="alert alert-secondary mb-2">
                            <i class="bi bi-clock-history me-2"></i>
                            Η βάρδια έχει ήδη ξεκινήσει
                        </div>
                    @elseif($isFull)
                        <!-- Η βάρδια είναι πλήρης -->
                        <div class="alert alert-info mb-2">
                            <i class="bi bi-people-fill me-2"></i>
                            Η βάρδια είναι <strong>πλήρης</strong>
                        </div>
                    @else
                        <!-- Κουμπί δήλωσης συμμετοχής -->
                        <form action="{{ route('participations.apply', $shift) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-success w-100 btn-lg">
                                <i class="bi bi-hand-index-thumb me-2"></i>Δήλωση Συμμετοχής
                            </button>
                        </form>
                    @endif
                    
                    <hr class="my-2">
                    
                    <a href="{{ route('missions.show', $shift->mission_id) }}" class="btn btn-outline-primary">
                        <i class="bi bi-flag me-2"></i>Προβολή Αποστολής
                    </a>
                    @if($user->isAdmin())
                    <a href="{{ route('shifts.edit', $shift) }}" class="btn btn-outline-secondary">
                        <i class="bi bi-pencil me-2"></i>Επεξεργασία
                    </a>
                    @endif
                    <a href="{{ route('shifts.index') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Πίσω στη Λίστα
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <form id="delete-form" action="{{ route('shifts.destroy', $shift) }}" method="POST" class="d-none">
        @csrf
        @method('DELETE')
    </form>
@endsection

@push('scripts')
<script>
    function confirmDelete() {
        if (confirm('Είστε σίγουροι ότι θέλετε να διαγράψετε αυτή τη βάρδια;')) {
            document.getElementById('delete-form').submit();
        }
    }
</script>
@endpush
