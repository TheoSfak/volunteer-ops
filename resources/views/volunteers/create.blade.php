@extends('layouts.app')

@section('title', $canCreateAdmin ? 'Νέος Χρήστης' : 'Νέος Εθελοντής')
@section('page-title', $canCreateAdmin ? 'Νέος Χρήστης' : 'Νέος Εθελοντής')

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <form action="{{ route('volunteers.store') }}" method="POST">
                @csrf
                
                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-person me-2"></i>Βασικές Πληροφορίες</div>
                    <div class="card-body">
                        @if($canCreateAdmin)
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="role" class="form-label">Ρόλος Χρήστη <span class="text-danger">*</span></label>
                                <select class="form-select @error('role') is-invalid @enderror" id="role" name="role">
                                    @foreach($roles as $value => $label)
                                        <option value="{{ $value }}" {{ old('role', 'VOLUNTEER') == $value ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <small class="text-muted">Ως Διαχειριστής Συστήματος μπορείτε να δημιουργήσετε χρήστες με οποιονδήποτε ρόλο.</small>
                            </div>
                        </div>
                        @endif
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">Ονοματεπώνυμο <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required>
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" required>
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Τηλέφωνο</label>
                                <input type="tel" class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone') }}">
                                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="department_id" class="form-label">Τμήμα</label>
                                <select class="form-select @error('department_id') is-invalid @enderror" id="department_id" name="department_id">
                                    <option value="">Επιλέξτε τμήμα...</option>
                                    @foreach($departments ?? [] as $dept)
                                        <option value="{{ $dept->id }}" {{ old('department_id') == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
                                    @endforeach
                                </select>
                                @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Κωδικός <span class="text-danger">*</span></label>
                                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="password_confirmation" class="form-label">Επιβεβαίωση Κωδικού <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4" id="profile-card">
                    <div class="card-header"><i class="bi bi-info-circle me-2"></i>Επιπλέον Στοιχεία <small class="text-muted">(Προφίλ Εθελοντή)</small></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="date_of_birth" class="form-label">Ημερομηνία Γέννησης</label>
                                <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" value="{{ old('date_of_birth') }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="blood_type" class="form-label">Ομάδα Αίματος</label>
                                <select class="form-select" id="blood_type" name="blood_type">
                                    <option value="">Επιλέξτε...</option>
                                    <option value="A+">A+</option><option value="A-">A-</option>
                                    <option value="B+">B+</option><option value="B-">B-</option>
                                    <option value="AB+">AB+</option><option value="AB-">AB-</option>
                                    <option value="O+">O+</option><option value="O-">O-</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">Διεύθυνση</label>
                            <input type="text" class="form-control" id="address" name="address" value="{{ old('address') }}">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="city" class="form-label">Πόλη</label>
                                <input type="text" class="form-control" id="city" name="city" value="{{ old('city') }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="postal_code" class="form-label">Τ.Κ.</label>
                                <input type="text" class="form-control" id="postal_code" name="postal_code" value="{{ old('postal_code') }}">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="emergency_contact" class="form-label">Επαφή Έκτακτης Ανάγκης</label>
                            <input type="text" class="form-control" id="emergency_contact" name="emergency_contact" value="{{ old('emergency_contact') }}" placeholder="Όνομα - Τηλέφωνο">
                        </div>
                        <div class="mb-3">
                            <label for="specialties" class="form-label">Ειδικότητες / Δεξιότητες</label>
                            <textarea class="form-control" id="specialties" name="specialties" rows="2">{{ old('specialties') }}</textarea>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Δημιουργία</button>
                    <a href="{{ route('volunteers.index') }}" class="btn btn-outline-secondary">Ακύρωση</a>
                </div>
            </form>
        </div>
        
        @if($canCreateAdmin)
        <div class="col-lg-4">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <i class="bi bi-info-circle me-2"></i>Πληροφορίες Ρόλων
                </div>
                <div class="card-body small">
                    <p><strong>Εθελοντής:</strong><br>Βασικός χρήστης, μπορεί να δηλώνει συμμετοχή σε βάρδιες.</p>
                    <p><strong>Υπεύθυνος Βάρδιας:</strong><br>Διαχειρίζεται τις βάρδιες που του έχουν ανατεθεί.</p>
                    <p><strong>Διαχειριστής Τμήματος:</strong><br>Διαχειρίζεται το τμήμα του, τις αποστολές και τους εθελοντές.</p>
                    <p class="mb-0"><strong>Διαχειριστής Συστήματος:</strong><br>Πλήρη πρόσβαση σε όλες τις λειτουργίες.</p>
                </div>
            </div>
        </div>
        @endif
    </div>
@endsection

@if($canCreateAdmin)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('role');
    const profileCard = document.getElementById('profile-card');
    
    function toggleProfileCard() {
        if (roleSelect.value === 'VOLUNTEER') {
            profileCard.style.display = 'block';
        } else {
            profileCard.style.display = 'none';
        }
    }
    
    roleSelect.addEventListener('change', toggleProfileCard);
    toggleProfileCard(); // Initial state
});
</script>
@endpush
@endif
