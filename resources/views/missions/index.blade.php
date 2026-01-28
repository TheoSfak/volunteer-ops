@extends('layouts.app')

@section('title', 'Αποστολές')
@section('page-title', 'Αποστολές')

@section('content')
    <!-- Header Actions -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <p class="text-muted mb-0">Διαχείριση εθελοντικών αποστολών</p>
        </div>
        @if(auth()->user()->isAdmin())
        <a href="{{ route('missions.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-2"></i>Νέα Αποστολή
        </a>
        @endif
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form action="{{ route('missions.index') }}" method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Αναζήτηση</label>
                    <input type="text" class="form-control" name="search" value="{{ request('search') }}" placeholder="Τίτλος, τοποθεσία...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Κατάσταση</label>
                    <select class="form-select" name="status">
                        <option value="">Όλες</option>
                        <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>Πρόχειρη</option>
                        <option value="published" {{ request('status') == 'published' ? 'selected' : '' }}>Δημοσιευμένη</option>
                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Ενεργή</option>
                        <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Ολοκληρωμένη</option>
                        <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Ακυρωμένη</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Τμήμα</label>
                    <select class="form-select" name="department_id">
                        <option value="">Όλα</option>
                        @foreach($departments ?? [] as $dept)
                            <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>
                                {{ $dept->name }}
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
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Missions List -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Αποστολή</th>
                            <th>Τμήμα</th>
                            <th>Τοποθεσία</th>
                            <th>Ημερομηνίες</th>
                            <th>Βάρδιες</th>
                            <th>Κατάσταση</th>
                            <th class="text-end">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($missions ?? [] as $mission)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="bg-primary bg-opacity-10 rounded p-2">
                                            <i class="bi bi-flag text-primary"></i>
                                        </div>
                                        <div>
                                            <div class="fw-medium">{{ $mission->title }}</div>
                                            <small class="text-muted">{{ Str::limit($mission->description, 50) }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark">
                                        {{ $mission->department->name ?? '-' }}
                                    </span>
                                </td>
                                <td>
                                    <i class="bi bi-geo-alt text-muted me-1"></i>
                                    {{ $mission->location ?? '-' }}
                                </td>
                                <td>
                                    @if($mission->start_datetime)
                                        <div>{{ $mission->start_datetime->format('d/m/Y') }}</div>
                                        <small class="text-muted">
                                            {{ $mission->end_datetime ? 'έως ' . $mission->end_datetime->format('d/m/Y') : '' }}
                                        </small>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-secondary">{{ $mission->shifts_count ?? 0 }}</span>
                                </td>
                                <td>
                                    @php
                                        $statusClasses = [
                                            'draft' => 'badge-draft',
                                            'published' => 'badge-published',
                                            'active' => 'badge-active',
                                            'completed' => 'badge-completed',
                                            'cancelled' => 'badge-cancelled',
                                        ];
                                        $statusLabels = [
                                            'draft' => 'Πρόχειρη',
                                            'published' => 'Δημοσιευμένη',
                                            'active' => 'Ενεργή',
                                            'completed' => 'Ολοκληρωμένη',
                                            'cancelled' => 'Ακυρωμένη',
                                        ];
                                    @endphp
                                    <span class="badge-status {{ $statusClasses[$mission->status] ?? 'badge-draft' }}">
                                        {{ $statusLabels[$mission->status] ?? $mission->status }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('missions.show', $mission) }}" class="btn btn-outline-primary" title="Προβολή">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        @if(auth()->user()->isAdmin())
                                        <a href="{{ route('missions.edit', $mission) }}" class="btn btn-outline-secondary" title="Επεξεργασία">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" title="Διαγραφή" 
                                                onclick="confirmDelete({{ $mission->id }})">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        @endif
                                    </div>
                                    @if(auth()->user()->isAdmin())
                                    <form id="delete-form-{{ $mission->id }}" action="{{ route('missions.destroy', $mission) }}" method="POST" class="d-none">
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
                                        <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                        <p class="mb-3">Δεν βρέθηκαν αποστολές</p>
                                        @if(auth()->user()->isAdmin())
                                        <a href="{{ route('missions.create') }}" class="btn btn-primary">
                                            <i class="bi bi-plus-lg me-2"></i>Δημιουργία Αποστολής
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
        
        @if(isset($missions) && $missions->hasPages())
            <div class="card-footer">
                {{ $missions->links() }}
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    function confirmDelete(id) {
        if (confirm('Είστε σίγουροι ότι θέλετε να διαγράψετε αυτή την αποστολή;')) {
            document.getElementById('delete-form-' + id).submit();
        }
    }
</script>
@endpush
