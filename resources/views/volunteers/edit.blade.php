@extends('layouts.app')

@section('title', 'Επεξεργασία: ' . $volunteer->name)
@section('page-title', 'Επεξεργασία Εθελοντή')

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <form action="{{ route('volunteers.update', $volunteer) }}" method="POST">
                @csrf
                @method('PUT')
                
                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-person me-2"></i>Βασικές Πληροφορίες</div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">Ονοματεπώνυμο <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $volunteer->name) }}" required>
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email', $volunteer->email) }}" required>
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Τηλέφωνο</label>
                                <input type="tel" class="form-control" id="phone" name="phone" value="{{ old('phone', $volunteer->phone) }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="department_id" class="form-label">Τμήμα</label>
                                <select class="form-select" id="department_id" name="department_id">
                                    <option value="">Επιλέξτε τμήμα...</option>
                                    @foreach($departments ?? [] as $dept)
                                        <option value="{{ $dept->id }}" {{ old('department_id', $volunteer->department_id) == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" {{ old('is_active', $volunteer->is_active) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">Ενεργός Εθελοντής</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-info-circle me-2"></i>Επιπλέον Στοιχεία</div>
                    <div class="card-body">
                        @php $profile = $volunteer->volunteerProfile; @endphp
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="date_of_birth" class="form-label">Ημερομηνία Γέννησης</label>
                                <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" value="{{ old('date_of_birth', $profile->date_of_birth ?? '') }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="blood_type" class="form-label">Ομάδα Αίματος</label>
                                <select class="form-select" id="blood_type" name="blood_type">
                                    <option value="">Επιλέξτε...</option>
                                    @foreach(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bt)
                                        <option value="{{ $bt }}" {{ old('blood_type', $profile->blood_type ?? '') == $bt ? 'selected' : '' }}>{{ $bt }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">Διεύθυνση</label>
                            <input type="text" class="form-control" id="address" name="address" value="{{ old('address', $profile->address ?? '') }}">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="city" class="form-label">Πόλη</label>
                                <input type="text" class="form-control" id="city" name="city" value="{{ old('city', $profile->city ?? '') }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="postal_code" class="form-label">Τ.Κ.</label>
                                <input type="text" class="form-control" id="postal_code" name="postal_code" value="{{ old('postal_code', $profile->postal_code ?? '') }}">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="emergency_contact" class="form-label">Επαφή Έκτακτης Ανάγκης</label>
                            <input type="text" class="form-control" id="emergency_contact" name="emergency_contact" value="{{ old('emergency_contact', $profile->emergency_contact ?? '') }}">
                        </div>
                        <div class="mb-3">
                            <label for="specialties" class="form-label">Ειδικότητες</label>
                            <textarea class="form-control" id="specialties" name="specialties" rows="2">{{ old('specialties', $profile->specialties ?? '') }}</textarea>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Αποθήκευση</button>
                    <a href="{{ route('volunteers.show', $volunteer) }}" class="btn btn-outline-secondary">Ακύρωση</a>
                </div>
            </form>
        </div>
    </div>
@endsection
