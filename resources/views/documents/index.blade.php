@extends('layouts.app')

@section('title', 'Έγγραφα')
@section('page-title', 'Έγγραφα')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <p class="text-muted mb-0">Διαχείριση εγγράφων και αρχείων</p>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
            <i class="bi bi-upload me-2"></i>Ανέβασμα
        </button>
    </div>
    
    <div class="card mb-4">
        <div class="card-body">
            <form action="{{ route('documents.index') }}" method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" class="form-control" name="search" value="{{ request('search') }}" placeholder="Αναζήτηση ονόματος...">
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="type">
                        <option value="">Όλοι οι τύποι</option>
                        <option value="mission" {{ request('type') == 'mission' ? 'selected' : '' }}>Αποστολές</option>
                        <option value="volunteer" {{ request('type') == 'volunteer' ? 'selected' : '' }}>Εθελοντές</option>
                        <option value="general" {{ request('type') == 'general' ? 'selected' : '' }}>Γενικά</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search"></i></button>
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
                            <th>Έγγραφο</th>
                            <th>Τύπος</th>
                            <th>Μέγεθος</th>
                            <th>Ημ. Ανεβάσματος</th>
                            <th>Ανέβασε</th>
                            <th class="text-end">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($documents ?? [] as $doc)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        @php
                                            $icons = ['pdf' => 'file-earmark-pdf text-danger', 'doc' => 'file-earmark-word text-primary', 'xls' => 'file-earmark-excel text-success', 'img' => 'file-earmark-image text-info'];
                                            $ext = strtolower(pathinfo($doc->original_name ?? $doc->title, PATHINFO_EXTENSION));
                                            $icon = $icons[$ext] ?? 'file-earmark text-secondary';
                                        @endphp
                                        <i class="bi bi-{{ $icon }} fs-4"></i>
                                        <div>
                                            <div class="fw-medium">{{ $doc->title }}</div>
                                            <small class="text-muted">{{ $doc->original_name ?? '' }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-light text-dark">{{ $doc->category ?? $doc->type ?? '-' }}</span></td>
                                <td>{{ $doc->file_size ? round($doc->file_size / 1024, 1) . ' KB' : '-' }}</td>
                                <td>{{ $doc->created_at ? $doc->created_at->format('d/m/Y H:i') : '-' }}</td>
                                <td>{{ $doc->creator->name ?? '-' }}</td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('documents.download', $doc) }}" class="btn btn-outline-primary" title="Λήψη"><i class="bi bi-download"></i></a>
                                        <button type="button" class="btn btn-outline-danger" onclick="confirmDelete({{ $doc->id }})" title="Διαγραφή"><i class="bi bi-trash"></i></button>
                                    </div>
                                    <form id="delete-form-{{ $doc->id }}" action="{{ route('documents.destroy', $doc) }}" method="POST" class="d-none">@csrf @method('DELETE')</form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-folder2-open fs-1 d-block mb-2"></i>Δεν υπάρχουν έγγραφα</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="{{ route('documents.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Ανέβασμα Εγγράφου</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="title" class="form-label">Τίτλος</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>
                        <div class="mb-3">
                            <label for="file" class="form-label">Αρχείο</label>
                            <input type="file" class="form-control" id="file" name="file" required>
                        </div>
                        <div class="mb-3">
                            <label for="category" class="form-label">Κατηγορία</label>
                            <select class="form-select" id="category" name="category">
                                <option value="general">Γενικό</option>
                                <option value="mission">Αποστολή</option>
                                <option value="volunteer">Εθελοντής</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Περιγραφή</label>
                            <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                        <button type="submit" class="btn btn-primary">Ανέβασμα</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    function confirmDelete(id) {
        if (confirm('Διαγραφή εγγράφου;')) document.getElementById('delete-form-' + id).submit();
    }
</script>
@endpush
