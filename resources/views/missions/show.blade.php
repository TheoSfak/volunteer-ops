@extends('layouts.app')

@section('title', $mission->title)
@section('page-title', $mission->title)

@section('content')
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            @php
                $statusClasses = [
                    'DRAFT' => 'badge-draft',
                    'OPEN' => 'badge-published',
                    'CLOSED' => 'badge-secondary',
                    'COMPLETED' => 'badge-completed',
                    'CANCELED' => 'badge-cancelled',
                ];
                $statusLabels = [
                    'DRAFT' => 'Πρόχειρη',
                    'OPEN' => 'Ανοιχτή',
                    'CLOSED' => 'Κλειστή',
                    'COMPLETED' => 'Ολοκληρωμένη',
                    'CANCELED' => 'Ακυρωμένη',
                ];
            @endphp
            <span class="badge-status {{ $statusClasses[$mission->status] ?? 'badge-draft' }} mb-2">
                {{ $statusLabels[$mission->status] ?? $mission->status }}
            </span>
            <p class="text-muted mb-0">{{ $mission->department->name ?? 'Χωρίς τμήμα' }}</p>
        </div>
        @if(auth()->user()->isAdmin())
        <div class="btn-group">
            <a href="{{ route('missions.edit', $mission) }}" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-2"></i>Επεξεργασία
            </a>
            <button type="button" class="btn btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown">
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                @if($mission->status === 'DRAFT')
                    <li>
                        <form action="{{ route('missions.publish', $mission) }}" method="POST">
                            @csrf
                            <button type="submit" class="dropdown-item">
                                <i class="bi bi-send me-2"></i>Άνοιγμα για Συμμετοχές
                            </button>
                        </form>
                    </li>
                @endif
                @if($mission->status === 'OPEN')
                    <li>
                        <form action="{{ route('missions.complete', $mission) }}" method="POST">
                            @csrf
                            <button type="submit" class="dropdown-item">
                                <i class="bi bi-check-circle me-2"></i>Ολοκλήρωση
                            </button>
                        </form>
                    </li>
                    <li>
                        <form action="{{ route('missions.cancel', $mission) }}" method="POST">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger">
                                <i class="bi bi-x-circle me-2"></i>Ακύρωση
                            </button>
                        </form>
                    </li>
                @endif
                <li><hr class="dropdown-divider"></li>
                <li>
                    <button type="button" class="dropdown-item text-danger" onclick="confirmDelete()">
                        <i class="bi bi-trash me-2"></i>Διαγραφή
                    </button>
                </li>
            </ul>
        </div>
        @endif
    </div>
    
    @if(auth()->user()->isAdmin())
    <form id="delete-form" action="{{ route('missions.destroy', $mission) }}" method="POST" class="d-none">
        @csrf
        @method('DELETE')
    </form>
    @endif
    
    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- Details Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-info-circle me-2"></i>Πληροφορίες Αποστολής
                </div>
                <div class="card-body">
                    <div class="mb-4">
                        <h6 class="text-muted mb-2">Περιγραφή</h6>
                        <p>{{ $mission->description ?: 'Δεν υπάρχει περιγραφή.' }}</p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <h6 class="text-muted mb-2">Τοποθεσία</h6>
                            <p class="mb-0">
                                <i class="bi bi-geo-alt text-primary me-2"></i>
                                {{ $mission->location ?: 'Δεν έχει οριστεί' }}
                            </p>
                            @if($mission->latitude && $mission->longitude)
                                <small class="text-muted">
                                    {{ $mission->latitude }}, {{ $mission->longitude }}
                                </small>
                            @endif
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <h6 class="text-muted mb-2">Τύπος Αποστολής</h6>
                            <p class="mb-0">
                                @php
                                    $typeLabels = [
                                        'VOLUNTEER' => 'ΕΘΕΛΟΝΤΙΚΗ',
                                        'MEDICAL' => 'ΙΑΤΡΙΚΗ',
                                        'general' => 'ΓΕΝΙΚΗ',
                                        'emergency' => 'ΕΚΤΑΚΤΗ',
                                        'recurring' => 'ΕΠΑΝΑΛΑΜΒΑΝΟΜΕΝΗ',
                                    ];
                                @endphp
                                <span class="badge bg-primary fs-5 text-uppercase">
                                    <i class="bi bi-tag me-1"></i>{{ $typeLabels[$mission->type] ?? strtoupper($mission->type) }}
                                </span>
                            </p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12 mb-3">
                            @php
                                $totalHours = 0;
                                if ($mission->start_datetime && $mission->end_datetime) {
                                    $totalHours = $mission->start_datetime->diffInHours($mission->end_datetime);
                                }
                            @endphp
                            <div class="alert alert-light border-start border-primary border-4 py-3">
                                <div class="d-flex align-items-center justify-content-center gap-4 flex-wrap">
                                    <div class="text-center">
                                        <small class="text-muted d-block">ΕΝΑΡΞΗ</small>
                                        <span class="fs-4 fw-bold text-primary">
                                            <i class="bi bi-calendar me-1"></i>
                                            {{ $mission->start_datetime ? $mission->start_datetime->format('d/m/Y') : '-' }}
                                        </span>
                                        <span class="fs-3 fw-bold text-dark ms-2">
                                            {{ $mission->start_datetime ? $mission->start_datetime->format('H:i') : '' }}
                                        </span>
                                    </div>
                                    <div class="text-muted fs-2">
                                        <i class="bi bi-arrow-right"></i>
                                    </div>
                                    <div class="text-center">
                                        <small class="text-muted d-block">ΛΗΞΗ</small>
                                        <span class="fs-4 fw-bold text-primary">
                                            <i class="bi bi-calendar-check me-1"></i>
                                            {{ $mission->end_datetime ? $mission->end_datetime->format('d/m/Y') : '-' }}
                                        </span>
                                        <span class="fs-3 fw-bold text-dark ms-2">
                                            {{ $mission->end_datetime ? $mission->end_datetime->format('H:i') : '' }}
                                        </span>
                                    </div>
                                    @if($totalHours > 0)
                                    <div class="text-center ms-3 ps-3 border-start">
                                        <small class="text-muted d-block">ΣΥΝΟΛΟ</small>
                                        <span class="fs-2 fw-bold text-success">
                                            <i class="bi bi-clock me-1"></i>{{ $totalHours }}
                                        </span>
                                        <span class="fs-5 text-success">ώρες</span>
                                    </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Shifts Card -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-event me-2"></i>Βάρδιες</span>
                    @if(auth()->user()->isAdmin())
                    <a href="{{ route('shifts.create', ['mission_id' => $mission->id]) }}" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg me-1"></i>Νέα Βάρδια
                    </a>
                    @endif
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Ημερομηνία</th>
                                    <th>Ώρες</th>
                                    <th>Υπεύθυνος</th>
                                    <th>Συμμετέχοντες</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($mission->shifts ?? [] as $shift)
                                    <tr>
                                        <td>{{ $shift->start_time ? $shift->start_time->format('d/m/Y') : '-' }}</td>
                                        <td>
                                            {{ $shift->start_time ? $shift->start_time->format('H:i') : '' }} - 
                                            {{ $shift->end_time ? $shift->end_time->format('H:i') : '' }}
                                        </td>
                                        <td>{{ $shift->leader->name ?? '-' }}</td>
                                        <td>
                                            <div class="progress" style="width: 100px; height: 20px;">
                                                @php
                                                    $count = $shift->participations_count ?? 0;
                                                    $max = $shift->max_capacity ?? 1;
                                                    $percent = min(100, ($count / $max) * 100);
                                                @endphp
                                                <div class="progress-bar {{ $percent >= 100 ? 'bg-success' : 'bg-primary' }}" 
                                                     style="width: {{ $percent }}%">
                                                    {{ $count }}/{{ $max }}
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="{{ route('shifts.show', $shift) }}" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            Δεν υπάρχουν βάρδιες
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
            <!-- Stats Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-bar-chart me-2"></i>Στατιστικά
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">Βάρδιες</span>
                        <strong>{{ $mission->shifts_count ?? $mission->shifts->count() ?? 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">Εθελοντές</span>
                        <strong>{{ $mission->volunteers_count ?? 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted">Αιτήσεις σε αναμονή</span>
                        <strong>{{ $mission->pending_requests_count ?? 0 }}</strong>
                    </div>
                </div>
            </div>
            
            <!-- Timeline -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-clock-history me-2"></i>Χρονολόγιο
                </div>
                <div class="card-body">
                    <div class="d-flex gap-3 mb-3">
                        <div class="text-muted" style="width: 80px;">Δημιουργία</div>
                        <div>{{ $mission->created_at ? $mission->created_at->format('d/m/Y H:i') : '-' }}</div>
                    </div>
                    <div class="d-flex gap-3 mb-3">
                        <div class="text-muted" style="width: 80px;">Ενημέρωση</div>
                        <div>{{ $mission->updated_at ? $mission->updated_at->format('d/m/Y H:i') : '-' }}</div>
                    </div>
                    @if($mission->created_by)
                        <div class="d-flex gap-3">
                            <div class="text-muted" style="width: 80px;">Δημιουργός</div>
                            <div>{{ $mission->creator->name ?? '-' }}</div>
                        </div>
                    @endif
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-lightning me-2"></i>Ενέργειες
                </div>
                <div class="card-body d-grid gap-2">
                    @php
                        $user = auth()->user();
                        // Έλεγχος αν ο εθελοντής έχει ήδη δηλώσει συμμετοχή στην αποστολή (σε οποιαδήποτε βάρδια)
                        $hasApplied = false;
                        $isPending = false;
                        $isApproved = false;
                        $userParticipation = null;
                        
                        foreach($mission->shifts as $shift) {
                            $participation = $shift->participations->where('volunteer_id', $user->id)->first();
                            if ($participation) {
                                $hasApplied = true;
                                $userParticipation = $participation;
                                if ($participation->status === 'PENDING') {
                                    $isPending = true;
                                }
                                if ($participation->status === 'APPROVED') {
                                    $isApproved = true;
                                }
                                break;
                            }
                        }
                        
                        $canApply = in_array($mission->status, ['OPEN']) && !$hasApplied;
                    @endphp
                    
                    @if($isApproved)
                        <div class="alert alert-success mb-2">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <strong>Συμμετέχετε</strong> σε αυτή την αποστολή!
                        </div>
                    @elseif($isPending)
                        <div class="alert alert-warning mb-2">
                            <i class="bi bi-hourglass-split me-2"></i>
                            Η αίτησή σας είναι <strong>σε αναμονή</strong>
                        </div>
                        <form action="{{ route('participations.cancel', $userParticipation) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100" 
                                    onclick="return confirm('Θέλετε να ακυρώσετε την αίτησή σας;')">
                                <i class="bi bi-x-circle me-2"></i>Ακύρωση Αίτησης
                            </button>
                        </form>
                        <hr class="my-2">
                    @elseif($canApply)
                        <form action="{{ route('missions.apply', $mission) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-success w-100 btn-lg mb-2">
                                <i class="bi bi-hand-index-thumb me-2"></i>Θέλω να Συμμετάσχω!
                            </button>
                        </form>
                        <hr class="my-2">
                    @elseif(!in_array($mission->status, ['OPEN']))
                        <div class="alert alert-secondary mb-2">
                            <i class="bi bi-info-circle me-2"></i>
                            Η αποστολή δεν δέχεται συμμετοχές
                        </div>
                    @endif
                    
                    @if($user->isAdmin())
                    <a href="{{ route('shifts.create', ['mission_id' => $mission->id]) }}" class="btn btn-outline-primary">
                        <i class="bi bi-plus-circle me-2"></i>Προσθήκη Βάρδιας
                    </a>
                    <a href="{{ route('documents.index', ['mission_id' => $mission->id]) }}" class="btn btn-outline-secondary">
                        <i class="bi bi-file-earmark me-2"></i>Έγγραφα
                    </a>
                    @endif
                    <a href="{{ route('missions.index') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Πίσω στη Λίστα
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    function confirmDelete() {
        if (confirm('Είστε σίγουροι ότι θέλετε να διαγράψετε αυτή την αποστολή; Αυτή η ενέργεια δεν μπορεί να αναιρεθεί.')) {
            document.getElementById('delete-form').submit();
        }
    }
</script>
@endpush
