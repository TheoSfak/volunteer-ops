@extends('layouts.app')

@section('title', 'Αποστολές')
@section('page-title', 'Αποστολές')

@section('content')
    <!-- Header Actions -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <span class="text-muted small">Διαχείριση εθελοντικών αποστολών</span>
        @if(auth()->user()->isAdmin())
        <a href="{{ route('missions.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Νέα Αποστολή
        </a>
        @endif
    </div>
    
    <!-- Compact Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form action="{{ route('missions.index') }}" method="GET" class="row g-2 align-items-end">
                <div class="col">
                    <input type="text" class="form-control form-control-sm" name="search" value="{{ request('search') }}" placeholder="Αναζήτηση...">
                </div>
                <div class="col-auto">
                    <select class="form-select form-select-sm" name="status" style="width: 130px;">
                        <option value="">Κατάσταση</option>
                        <option value="DRAFT" {{ request('status') == 'DRAFT' ? 'selected' : '' }}>Πρόχειρη</option>
                        <option value="OPEN" {{ request('status') == 'OPEN' ? 'selected' : '' }}>Ανοιχτή</option>
                        <option value="CLOSED" {{ request('status') == 'CLOSED' ? 'selected' : '' }}>Κλειστή</option>
                        <option value="COMPLETED" {{ request('status') == 'COMPLETED' ? 'selected' : '' }}>Ολοκληρωμένη</option>
                        <option value="CANCELED" {{ request('status') == 'CANCELED' ? 'selected' : '' }}>Ακυρωμένη</option>
                    </select>
                </div>
                <div class="col-auto">
                    <select class="form-select form-select-sm" name="department_id" style="width: 140px;">
                        <option value="">Τμήμα</option>
                        @foreach($departments ?? [] as $dept)
                            <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>
                                {{ $dept->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <input type="date" class="form-control form-control-sm" name="from_date" value="{{ request('from_date') }}" title="Από">
                </div>
                <div class="col-auto">
                    <input type="date" class="form-control form-control-sm" name="to_date" value="{{ request('to_date') }}" title="Έως">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-search"></i>
                    </button>
                    @if(request()->hasAny(['search', 'status', 'department_id', 'from_date', 'to_date']))
                    <a href="{{ route('missions.index') }}" class="btn btn-outline-secondary btn-sm" title="Καθαρισμός">
                        <i class="bi bi-x-lg"></i>
                    </a>
                    @endif
                </div>
            </form>
        </div>
    </div>
    
    <!-- Compact Missions Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Αποστολή</th>
                            <th>Τμήμα</th>
                            <th>Τοποθεσία</th>
                            <th>Ημερομηνία</th>
                            <th class="text-center">Βάρδιες</th>
                            <th>Κατάσταση</th>
                            <th class="text-end pe-3" style="width: 100px;">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($missions ?? [] as $mission)
                            <tr>
                                <td class="ps-3">
                                    <a href="{{ route('missions.show', $mission) }}" class="text-decoration-none fw-medium">
                                        {{ Str::limit($mission->title, 35) }}
                                    </a>
                                    @if($mission->type === 'MEDICAL')
                                        <i class="bi bi-heart-pulse text-danger ms-1" title="Ιατρική"></i>
                                    @endif
                                </td>
                                <td>
                                    <small class="text-muted">{{ $mission->department->name ?? '-' }}</small>
                                </td>
                                <td>
                                    <small><i class="bi bi-geo-alt text-muted"></i> {{ Str::limit($mission->location ?? '-', 20) }}</small>
                                </td>
                                <td>
                                    @if($mission->start_datetime)
                                        <small>{{ $mission->start_datetime->format('d/m/y') }}
                                        @if($mission->end_datetime && $mission->end_datetime->format('d/m/y') !== $mission->start_datetime->format('d/m/y'))
                                            - {{ $mission->end_datetime->format('d/m/y') }}
                                        @endif
                                        </small>
                                    @else
                                        <small class="text-muted">-</small>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary bg-opacity-75">{{ $mission->shifts_count ?? $mission->shifts->count() ?? 0 }}</span>
                                </td>
                                <td>
                                    @php
                                        $statusClasses = [
                                            'DRAFT' => 'bg-secondary',
                                            'OPEN' => 'bg-success',
                                            'CLOSED' => 'bg-warning text-dark',
                                            'COMPLETED' => 'bg-primary',
                                            'CANCELED' => 'bg-danger',
                                        ];
                                        $statusLabels = [
                                            'DRAFT' => 'Πρόχειρη',
                                            'OPEN' => 'Ανοιχτή',
                                            'CLOSED' => 'Κλειστή',
                                            'COMPLETED' => 'Ολοκληρωμένη',
                                            'CANCELED' => 'Ακυρωμένη',
                                        ];
                                    @endphp
                                    <span class="badge {{ $statusClasses[$mission->status] ?? 'bg-secondary' }}" style="font-size: 0.7rem;">
                                        {{ $statusLabels[$mission->status] ?? $mission->status }}
                                    </span>
                                </td>
                                <td class="text-end pe-3">
                                    <a href="{{ route('missions.show', $mission) }}" class="btn btn-link btn-sm p-0 me-2" title="Προβολή">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @if(auth()->user()->isAdmin())
                                    <a href="{{ route('missions.edit', $mission) }}" class="btn btn-link btn-sm p-0 me-2 text-secondary" title="Επεξεργασία">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-danger" title="Διαγραφή" onclick="confirmDelete({{ $mission->id }})">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <form id="delete-form-{{ $mission->id }}" action="{{ route('missions.destroy', $mission) }}" method="POST" class="d-none">
                                        @csrf
                                        @method('DELETE')
                                    </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <i class="bi bi-inbox text-muted fs-4 d-block mb-2"></i>
                                    <span class="text-muted">Δεν βρέθηκαν αποστολές</span>
                                    @if(auth()->user()->isAdmin())
                                    <br><a href="{{ route('missions.create') }}" class="btn btn-sm btn-primary mt-2">
                                        <i class="bi bi-plus-lg me-1"></i>Νέα Αποστολή
                                    </a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        
        @if(isset($missions) && $missions->hasPages())
            <div class="card-footer py-2">
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
