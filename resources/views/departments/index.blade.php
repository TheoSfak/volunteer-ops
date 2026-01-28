@extends('layouts.app')

@section('title', 'Τμήματα')
@section('page-title', 'Τμήματα')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <p class="text-muted mb-0">Διαχείριση τμημάτων οργανισμού</p>
        <a href="{{ route('departments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Νέο Τμήμα</a>
    </div>
    
    <div class="row g-4">
        @forelse($departments ?? [] as $dept)
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="bg-primary bg-opacity-10 rounded p-3">
                                <i class="bi bi-building text-primary fs-4"></i>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-link" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="{{ route('departments.edit', $dept) }}"><i class="bi bi-pencil me-2"></i>Επεξεργασία</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form action="{{ route('departments.destroy', $dept) }}" method="POST" onsubmit="return confirm('Διαγραφή τμήματος;')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Διαγραφή</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <h5 class="card-title mb-2">{{ $dept->name }}</h5>
                        <p class="card-text text-muted small mb-3">{{ Str::limit($dept->description, 80) }}</p>
                        <div class="d-flex gap-3">
                            <small class="text-muted"><i class="bi bi-people me-1"></i>{{ $dept->users_count ?? 0 }} μέλη</small>
                            <small class="text-muted"><i class="bi bi-flag me-1"></i>{{ $dept->missions_count ?? 0 }} αποστολές</small>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-building fs-1 d-block mb-2"></i>
                    <p>Δεν υπάρχουν τμήματα</p>
                    <a href="{{ route('departments.create') }}" class="btn btn-primary">Δημιουργία Τμήματος</a>
                </div>
            </div>
        @endforelse
    </div>
@endsection
