@extends('layouts.app')

@section('title', 'Νέα Βάρδια')
@section('page-title', 'Νέα Βάρδια')

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <form action="{{ route('shifts.store') }}" method="POST">
                @csrf
                
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-flag me-2"></i>Αποστολή
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="mission_id" class="form-label">Επιλέξτε Αποστολή <span class="text-danger">*</span></label>
                            <select class="form-select @error('mission_id') is-invalid @enderror" 
                                    id="mission_id" name="mission_id" required>
                                <option value="">Επιλέξτε αποστολή...</option>
                                @foreach($missions ?? [] as $mission)
                                    <option value="{{ $mission->id }}" {{ old('mission_id', request('mission_id')) == $mission->id ? 'selected' : '' }}>
                                        {{ $mission->title }} ({{ $mission->start_datetime ? $mission->start_datetime->format('d/m/Y') : 'Χωρίς ημ/νία' }})
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
                                       id="start_time" name="start_time" value="{{ old('start_time') }}" required>
                                @error('start_time')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="end_time" class="form-label">Λήξη <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control @error('end_time') is-invalid @enderror" 
                                       id="end_time" name="end_time" value="{{ old('end_time') }}" required>
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
                                       id="max_capacity" name="max_capacity" value="{{ old('max_capacity', 10) }}" required>
                                @error('max_capacity')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="min_capacity" class="form-label">Ελάχιστος Αριθμός Εθελοντών</label>
                                <input type="number" min="0" class="form-control @error('min_capacity') is-invalid @enderror" 
                                       id="min_capacity" name="min_capacity" value="{{ old('min_capacity', 1) }}">
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
                                    <option value="{{ $leader->id }}" {{ old('leader_id') == $leader->id ? 'selected' : '' }}>
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
                                      id="notes" name="notes" rows="3" 
                                      placeholder="Ειδικές οδηγίες, απαιτήσεις εξοπλισμού, κλπ.">{{ old('notes') }}</textarea>
                            @error('notes')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="requires_approval" id="requires_approval" 
                                   {{ old('requires_approval', true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="requires_approval">
                                Απαιτείται έγκριση συμμετοχής
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>Δημιουργία Βάρδιας
                    </button>
                    <a href="{{ route('shifts.index') }}" class="btn btn-outline-secondary">
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
                        <strong>Αποστολή:</strong> Επιλέξτε σε ποια αποστολή ανήκει αυτή η βάρδια.
                    </p>
                    <p class="small text-muted mb-3">
                        <strong>Χρονοδιάγραμμα:</strong> Ορίστε την ώρα έναρξης και λήξης της βάρδιας.
                    </p>
                    <p class="small text-muted mb-3">
                        <strong>Χωρητικότητα:</strong> Καθορίστε πόσους εθελοντές χρειάζεστε (ελάχιστο και μέγιστο).
                    </p>
                    <p class="small text-muted mb-0">
                        <strong>Υπεύθυνος:</strong> Ορίστε έναν Team Leader που θα συντονίζει τη βάρδια.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
