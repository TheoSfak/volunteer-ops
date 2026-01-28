@extends('layouts.app')

@section('title', 'Νέα Αποστολή')
@section('page-title', 'Δημιουργία Νέας Αποστολής')

@php
    $missionTypes = ['VOLUNTEER' => 'Εθελοντική', 'MEDICAL' => 'Υγειονομική'];
@endphp

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <form action="{{ route('missions.store') }}" method="POST">
                @csrf

                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-info-circle me-2"></i>Βασικές Πληροφορίες
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="title" class="form-label">Τίτλος Αποστολής <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('title') is-invalid @enderror"
                                   id="title" name="title" value="{{ old('title') }}"
                                   placeholder="π.χ. Διανομή Τροφίμων - Κεντρική Αθήνα" required>
                            @error('title')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Περιγραφή</label>
                            <textarea class="form-control @error('description') is-invalid @enderror"
                                      id="description" name="description" rows="4"
                                      placeholder="Περιγράψτε την αποστολή, τους στόχους και τις απαιτήσεις...">{{ old('description') }}</textarea>
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
                                        <option value="{{ $dept->id }}" {{ old('department_id') == $dept->id ? 'selected' : '' }}>
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
                                    @foreach($missionTypes ?? ['VOLUNTEER' => 'Εθελοντική', 'MEDICAL' => 'Υγειονομική'] as $key => $label)
                                        <option value="{{ $key }}" {{ old('type', 'VOLUNTEER') == $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
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
                                   id="location" name="location" value="{{ old('location') }}"
                                   placeholder="π.χ. Πλατεία Συντάγματος, Αθήνα">
                            @error('location')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="latitude" class="form-label">Γεωγραφικό Πλάτος</label>
                                <input type="number" step="0.0000001" class="form-control @error('latitude') is-invalid @enderror"
                                       id="latitude" name="latitude" value="{{ old('latitude') }}"
                                       placeholder="π.χ. 37.9755">
                                @error('latitude')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="longitude" class="form-label">Γεωγραφικό Μήκος</label>
                                <input type="number" step="0.0000001" class="form-control @error('longitude') is-invalid @enderror"
                                       id="longitude" name="longitude" value="{{ old('longitude') }}"
                                       placeholder="π.χ. 23.7348">
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
                                       id="start_datetime" name="start_datetime" value="{{ old('start_datetime') }}" required>
                                @error('start_datetime')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="end_datetime" class="form-label">Ημερομηνία Λήξης</label>
                                <input type="datetime-local" class="form-control @error('end_datetime') is-invalid @enderror"
                                       id="end_datetime" name="end_datetime" value="{{ old('end_datetime') }}">
                                @error('end_datetime')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" name="status" value="DRAFT" class="btn btn-secondary">
                        <i class="bi bi-file-earmark me-2"></i>Αποθήκευση ως Πρόχειρο
                    </button>
                    <button type="submit" name="status" value="OPEN" class="btn btn-primary">
                        <i class="bi bi-send me-2"></i>Δημοσίευση
                    </button>
                    <a href="{{ route('missions.index') }}" class="btn btn-outline-secondary">
                        Ακύρωση
                    </a>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-lightbulb me-2"></i>Οδηγίες
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        <strong>Τίτλος:</strong> Επιλέξτε έναν σαφή και περιγραφικό τίτλο που να αντικατοπτρίζει τη φύση της αποστολής.
                    </p>
                    <p class="small text-muted mb-3">
                        <strong>Τμήμα:</strong> Επιλέξτε το τμήμα που θα διαχειρίζεται την αποστολή.
                    </p>
                    <p class="small text-muted mb-3">
                        <strong>Τοποθεσία:</strong> Καταχωρήστε τη διεύθυνση ή τις συντεταγμένες για εύκολη πλοήγηση.
                    </p>
                    <p class="small text-muted mb-0">
                        <strong>Κατάσταση:</strong> Αποθηκεύστε ως πρόχειρο για να συνεχίσετε αργότερα ή δημοσιεύστε για να γίνει ορατή.
                    </p>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header">
                    <i class="bi bi-tags me-2"></i>Τύποι Αποστολών
                </div>
                <div class="card-body">
                    @foreach($missionTypes as $type)
                        <div class="d-flex align-items-start gap-2 mb-2">
                            <span class="badge bg-{{ $type === 'Υγειονομική' ? 'danger' : 'primary' }}">{{ $type }}</span>
                        </div>
                    @endforeach
                    <small class="text-muted d-block mt-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Οι τύποι διαχειρίζονται από τις <a href="{{ route('settings.index') }}?tab=general">Ρυθμίσεις</a>.
                    </small>
                </div>
            </div>
        </div>
    </div>
@endsection
