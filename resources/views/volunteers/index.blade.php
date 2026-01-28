@extends('layouts.app')

@section('title', 'Εθελοντές')
@section('page-title', 'Εθελοντές')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <p class="text-muted mb-0">Διαχείριση εθελοντών</p>
        <a href="{{ route('volunteers.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-2"></i>Νέος Εθελοντής
        </a>
    </div>
    
    <div class="card mb-4">
        <div class="card-body">
            <form action="{{ route('volunteers.index') }}" method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" class="form-control" name="search" value="{{ request('search') }}" placeholder="Αναζήτηση ονόματος, email...">
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="department_id">
                        <option value="">Όλα τα τμήματα</option>
                        @foreach($departments ?? [] as $dept)
                            <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="status">
                        <option value="">Όλες οι καταστάσεις</option>
                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Ενεργοί</option>
                        <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Ανενεργοί</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search me-1"></i>Αναζήτηση</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Εθελοντής</th>
                            <th>Τηλέφωνο</th>
                            <th>Τμήμα</th>
                            <th>Συμμετοχές</th>
                            <th>Κατάσταση</th>
                            <th class="text-end">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($volunteers ?? [] as $volunteer)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="bg-info bg-opacity-10 rounded-circle p-2">
                                            <i class="bi bi-person text-info"></i>
                                        </div>
                                        <div>
                                            <div class="fw-medium">{{ $volunteer->name }}</div>
                                            <small class="text-muted">{{ $volunteer->email }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $volunteer->phone ?? '-' }}</td>
                                <td>{{ $volunteer->department->name ?? '-' }}</td>
                                <td><span class="badge bg-secondary">{{ $volunteer->participations_count ?? 0 }}</span></td>
                                <td>
                                    <span class="badge {{ $volunteer->is_active ? 'bg-success' : 'bg-secondary' }}">
                                        {{ $volunteer->is_active ? 'Ενεργός' : 'Ανενεργός' }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('volunteers.show', $volunteer) }}" class="btn btn-outline-primary"><i class="bi bi-eye"></i></a>
                                        <a href="{{ route('volunteers.edit', $volunteer) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-people fs-1 d-block mb-2"></i>Δεν βρέθηκαν εθελοντές
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if(isset($volunteers) && $volunteers->hasPages())
            <div class="card-footer">{{ $volunteers->links() }}</div>
        @endif
    </div>
@endsection
