@extends('layouts.auth')

@section('title', 'Εγγραφή')

@section('content')
    <div class="auth-title">
        <h4>Δημιουργία Λογαριασμού</h4>
        <p>Γίνετε μέλος της ομάδας μας</p>
    </div>
    
    <form action="{{ route('register') }}" method="POST">
        @csrf
        
        <div class="mb-3">
            <label for="name" class="form-label">Ονοματεπώνυμο</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person"></i></span>
                <input type="text" 
                       class="form-control @error('name') is-invalid @enderror" 
                       id="name" 
                       name="name" 
                       value="{{ old('name') }}" 
                       placeholder="π.χ. Γιάννης Παπαδόπουλος"
                       required 
                       autofocus>
            </div>
            @error('name')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>
        
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email" 
                       class="form-control @error('email') is-invalid @enderror" 
                       id="email" 
                       name="email" 
                       value="{{ old('email') }}" 
                       placeholder="you@example.com"
                       required>
            </div>
            @error('email')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>
        
        <div class="mb-3">
            <label for="phone" class="form-label">Τηλέφωνο</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-phone"></i></span>
                <input type="tel" 
                       class="form-control @error('phone') is-invalid @enderror" 
                       id="phone" 
                       name="phone" 
                       value="{{ old('phone') }}" 
                       placeholder="69xxxxxxxx">
            </div>
            @error('phone')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>
        
        <div class="mb-3">
            <label for="password" class="form-label">Κωδικός</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" 
                       class="form-control @error('password') is-invalid @enderror" 
                       id="password" 
                       name="password" 
                       placeholder="Τουλάχιστον 8 χαρακτήρες"
                       required>
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>
        
        <div class="mb-4">
            <label for="password_confirmation" class="form-label">Επιβεβαίωση Κωδικού</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                <input type="password" 
                       class="form-control" 
                       id="password_confirmation" 
                       name="password_confirmation" 
                       placeholder="Επαναλάβετε τον κωδικό"
                       required>
            </div>
        </div>
        
        <div class="form-check mb-4">
            <input class="form-check-input @error('terms') is-invalid @enderror" 
                   type="checkbox" 
                   name="terms" 
                   id="terms" 
                   required>
            <label class="form-check-label" for="terms">
                Αποδέχομαι τους <a href="#">Όρους Χρήσης</a> και την <a href="#">Πολιτική Απορρήτου</a>
            </label>
            @error('terms')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>
        
        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-person-plus me-2"></i>Εγγραφή
        </button>
    </form>
    
    <div class="auth-footer">
        <p class="text-muted mb-0">
            Έχετε ήδη λογαριασμό; 
            <a href="{{ route('login') }}">Σύνδεση</a>
        </p>
    </div>
@endsection
