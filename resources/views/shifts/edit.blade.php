@extends('layouts.app')

@section('title', 'Επεξεργασία Βάρδιας')
@section('page-title', 'Επεξεργασία Βάρδιας')

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <form action="{{ route('shifts.update', $shift) }}" method="POST">
                @csrf
                @method('PUT')
                
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-flag me-2"></i>Αποστολή
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="mission_id" class="form-label">Αποστολή <span class="text-danger">*</span></label>
                            <select class="form-select @error('mission_id') is-invalid @enderror" 
                                    id="mission_id" name="mission_id" required>
                                <option value="">Επιλέξτε αποστολή...</option>
                                @foreach($missions ?? [] as $mission)
                                    <option value="{{ $mission->id }}" {{ old('mission_id', $shift->mission_id) == $mission->id ? 'selected' : '' }}>
                                        {{ $mission->title }}
                                    </option>
                                @endforeach
                            </select>
                            @error('mission_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-clock me-2"></i>Χρονοδιάγραμμα
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="start_time" class="form-label">Έναρξη <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control @error('start_time') is-invalid @enderror" 
                                       id="start_time" name="start_time" 
                                       value="{{ old('start_time', $shift->start_time ? $shift->start_time->format('Y-m-d\TH:i') : '') }}" required>
                                @error('start_time')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="end_time" class="form-label">Λήξη <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control @error('end_time') is-invalid @enderror" 
                                       id="end_time" name="end_time" 
                                       value="{{ old('end_time', $shift->end_time ? $shift->end_time->format('Y-m-d\TH:i') : '') }}" required>
                                @error('end_time')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-people me-2"></i>Ομάδα
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="max_capacity" class="form-label">Μέγιστος Αριθμός Εθελοντών <span class="text-danger">*</span></label>
                                <input type="number" min="1" class="form-control @error('max_capacity') is-invalid @enderror" 
                                       id="max_capacity" name="max_capacity" value="{{ old('max_capacity', $shift->max_capacity) }}" required>
                                @error('max_capacity')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="min_capacity" class="form-label">Ελάχιστος Αριθμός Εθελοντών</label>
                                <input type="number" min="0" class="form-control @error('min_capacity') is-invalid @enderror" 
                                       id="min_capacity" name="min_capacity" value="{{ old('min_capacity', $shift->min_capacity) }}">
                                @error('min_capacity')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="leader_id" class="form-label">Υπεύθυνος Βάρδιας</label>
                            <select class="form-select @error('leader_id') is-invalid @enderror" 
                                    id="leader_id" name="leader_id">
                                <option value="">Χωρίς υπεύθυνο</option>
                                @foreach($leaders ?? [] as $leader)
                                    <option value="{{ $leader->id }}" {{ old('leader_id', $shift->leader_id) == $leader->id ? 'selected' : '' }}>
                                        {{ $leader->name }} ({{ $leader->email }})
                                    </option>
                                @endforeach
                            </select>
                            @error('leader_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-info-circle me-2"></i>Επιπλέον Πληροφορίες
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="notes" class="form-label">Σημειώσεις</label>
                            <textarea class="form-control @error('notes') is-invalid @enderror" 
                                      id="notes" name="notes" rows="3">{{ old('notes', $shift->notes) }}</textarea>
                            @error('notes')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>Αποθήκευση Αλλαγών
                    </button>
                    <a href="{{ route('shifts.show', $shift) }}" class="btn btn-outline-secondary">
                        Ακύρωση
                    </a>
                </div>
            </form>
        </div>
        
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-info-circle me-2"></i>Πληροφορίες
                </div>
                <div class="card-body">
                    <div class="d-flex gap-3 mb-3">
                        <div class="text-muted" style="width: 100px;">Δημιουργία</div>
                        <div>{{ $shift->created_at ? $shift->created_at->format('d/m/Y H:i') : '-' }}</div>
                    </div>
                    <div class="d-flex gap-3 mb-3">
                        <div class="text-muted" style="width: 100px;">Ενημέρωση</div>
                        <div>{{ $shift->updated_at ? $shift->updated_at->format('d/m/Y H:i') : '-' }}</div>
                    </div>
                    <div class="d-flex gap-3">
                        <div class="text-muted" style="width: 100px;">Συμμετέχοντες</div>
                        <div>{{ $shift->participations->where('status', 'approved')->count() }}</div>
                    </div>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header text-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>Ζώνη Κινδύνου
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Η διαγραφή της βάρδιας θα διαγράψει επίσης όλες τις αιτήσεις συμμετοχής.
                    </p>
                    <button type="button" class="btn btn-outline-danger w-100" onclick="confirmDelete()">
                        <i class="bi bi-trash me-2"></i>Διαγραφή Βάρδιας
                    </button>
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
