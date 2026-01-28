@extends('layouts.app')

@section('title', 'Επεξεργασία: ' . $mission->title)
@section('page-title', 'Επεξεργασία Αποστολής')

@php
    $missionTypes = ['VOLUNTEER' => 'Εθελοντική', 'MEDICAL' => 'Υγειονομική'];
@endphp

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <form action="{{ route('missions.update', $mission) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-info-circle me-2"></i>Βασικές Πληροφορίες
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="title" class="form-label">Τίτλος Αποστολής <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('title') is-invalid @enderror"
                                   id="title" name="title" value="{{ old('title', $mission->title) }}" required>
                            @error('title')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Περιγραφή</label>
                            <textarea class="form-control @error('description') is-invalid @enderror"
                                      id="description" name="description" rows="4">{{ old('description', $mission->description) }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="department_id" class="form-label">Τμήμα <span class="text-danger">*</span></label>
                                <select class="form-select @error('department_id') is-invalid @enderror"
                                        id="department_id" name="department_id" required>
                                    <option value="">Επιλέξτε τμήμα...</option>
                                    @foreach($departments ?? [] as $dept)
                                        <option value="{{ $dept->id }}" {{ old('department_id', $mission->department_id) == $dept->id ? 'selected' : '' }}>
                                            {{ $dept->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('department_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="type" class="form-label">Τύπος Αποστολής</label>
                                <select class="form-select @error('type') is-invalid @enderror"
                                        id="type" name="type">
                                    @foreach($missionTypes as $key => $label)
                                        <option value="{{ $key }}" {{ old('type', $mission->type) == $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="status" class="form-label">Κατάσταση</label>
                            <select class="form-select @error('status') is-invalid @enderror"
                                    id="status" name="status">
                                <option value="DRAFT" {{ old('status', $mission->status) == 'DRAFT' ? 'selected' : '' }}>Πρόχειρη</option>
                                <option value="OPEN" {{ old('status', $mission->status) == 'OPEN' ? 'selected' : '' }}>Ανοιχτή</option>
                                <option value="CLOSED" {{ old('status', $mission->status) == 'CLOSED' ? 'selected' : '' }}>Κλειστή</option>
                                <option value="COMPLETED" {{ old('status', $mission->status) == 'COMPLETED' ? 'selected' : '' }}>Ολοκληρωμένη</option>
                                <option value="CANCELED" {{ old('status', $mission->status) == 'CANCELED' ? 'selected' : '' }}>Ακυρωμένη</option>
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-geo-alt me-2"></i>Τοποθεσία
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="location" class="form-label">Διεύθυνση / Περιοχή</label>
                            <input type="text" class="form-control @error('location') is-invalid @enderror"
                                   id="location" name="location" value="{{ old('location', $mission->location) }}">
                            @error('location')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="latitude" class="form-label">Γεωγραφικό Πλάτος</label>
                                <input type="number" step="0.0000001" class="form-control @error('latitude') is-invalid @enderror"
                                       id="latitude" name="latitude" value="{{ old('latitude', $mission->latitude) }}">
                                @error('latitude')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="longitude" class="form-label">Γεωγραφικό Μήκος</label>
                                <input type="number" step="0.0000001" class="form-control @error('longitude') is-invalid @enderror"
                                       id="longitude" name="longitude" value="{{ old('longitude', $mission->longitude) }}">
                                @error('longitude')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-calendar me-2"></i>Χρονοδιάγραμμα
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="start_datetime" class="form-label">Ημερομηνία Έναρξης <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control @error('start_datetime') is-invalid @enderror"
                                       id="start_datetime" name="start_datetime"
                                       value="{{ old('start_datetime', $mission->start_datetime ? $mission->start_datetime->format('Y-m-d\TH:i') : '') }}" required>
                                @error('start_datetime')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="end_datetime" class="form-label">Ημερομηνία Λήξης</label>
                                <input type="datetime-local" class="form-control @error('end_datetime') is-invalid @enderror"
                                       id="end_datetime" name="end_datetime"
                                       value="{{ old('end_datetime', $mission->end_datetime ? $mission->end_datetime->format('Y-m-d\TH:i') : '') }}">
                                @error('end_datetime')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>Αποθήκευση Αλλαγών
                    </button>
                    <a href="{{ route('missions.show', $mission) }}" class="btn btn-outline-secondary">
                        Ακύρωση
                    </a>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-clock-history me-2"></i>Πληροφορίες
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
                    <div class="d-flex gap-3">
                        <div class="text-muted" style="width: 80px;">Βάρδιες</div>
                        <div>{{ $mission->shifts_count ?? $mission->shifts->count() ?? 0 }}</div>
                    </div>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header text-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>Ζώνη Κινδύνου
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Η διαγραφή της αποστολής θα διαγράψει επίσης όλες τις σχετικές βάρδιες και αιτήσεις συμμετοχής.
                    </p>
                    <button type="button" class="btn btn-outline-danger w-100" onclick="confirmDelete()">
                        <i class="bi bi-trash me-2"></i>Διαγραφή Αποστολής
                    </button>
                </div>
            </div>
        </div>
    </div>

    <form id="delete-form" action="{{ route('missions.destroy', $mission) }}" method="POST" class="d-none">
        @csrf
        @method('DELETE')
    </form>
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
