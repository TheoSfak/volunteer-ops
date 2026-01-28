@extends('layouts.auth')

@section('title', 'Επαναφορά Κωδικού')

@section('content')
    <div class="auth-title">
        <h4>Ξεχάσατε τον κωδικό σας;</h4>
        <p>Εισάγετε το email σας για να λάβετε οδηγίες επαναφοράς</p>
    </div>
    
    @if(session('status'))
        <div class="alert alert-success mb-4">
            <i class="bi bi-check-circle me-2"></i>{{ session('status') }}
        </div>
    @endif
    
    <form action="{{ route('password.email') }}" method="POST">
        @csrf
        
        <div class="mb-4">
            <label for="email" class="form-label">Email</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email" 
                       class="form-control @error('email') is-invalid @enderror" 
                       id="email" 
                       name="email" 
                       value="{{ old('email') }}" 
                       placeholder="you@example.com"
                       required 
                       autofocus>
            </div>
            @error('email')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>
        
        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-send me-2"></i>Αποστολή Οδηγιών
        </button>
    </form>
    
    <div class="auth-footer">
        <p class="text-muted mb-0">
            Θυμηθήκατε τον κωδικό σας; 
            <a href="{{ route('login') }}">Σύνδεση</a>
        </p>
    </div>
@endsection
