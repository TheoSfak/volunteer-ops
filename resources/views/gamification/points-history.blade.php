@extends('layouts.app')

@section('title', 'Ιστορικό Πόντων')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-0">
                <i class="fas fa-history text-info me-2"></i>
                Ιστορικό Πόντων
            </h1>
            <p class="text-muted">Δες αναλυτικά πώς κέρδισες τους πόντους σου!</p>
        </div>
    </div>

    <!-- Points Summary -->
    <div class="row mb-4">
        <div class="col-md-6 col-lg-3 mb-3">
            <div class="card bg-primary text-white h-100">
                <div class="card-body text-center">
                    <i class="fas fa-coins fa-2x mb-2"></i>
                    <h3 class="mb-0">{{ number_format($totalPoints) }}</h3>
                    <small>Συνολικοί Πόντοι</small>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <div class="card bg-success text-white h-100">
                <div class="card-body text-center">
                    <i class="fas fa-calendar-alt fa-2x mb-2"></i>
                    <h3 class="mb-0">{{ number_format($monthlyPoints) }}</h3>
                    <small>Μηνιαίοι Πόντοι</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Points History Table -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Αναλυτικό Ιστορικό
                    </h5>
                </div>
                <div class="card-body p-0">
                    @if($points->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ημερομηνία</th>
                                        <th>Λόγος</th>
                                        <th>Περιγραφή</th>
                                        <th class="text-end">Πόντοι</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($points as $point)
                                        <tr>
                                            <td>
                                                <span class="text-muted">
                                                    {{ $point->created_at->format('d/m/Y H:i') }}
                                                </span>
                                            </td>
                                            <td>
                                                @switch($point->reason)
                                                    @case('shift_completed')
                                                        <span class="badge bg-success">
                                                            <i class="fas fa-check me-1"></i>
                                                            {{ $point->reason_label }}
                                                        </span>
                                                        @break
                                                    @case('weekend_bonus')
                                                        <span class="badge bg-warning text-dark">
                                                            <i class="fas fa-sun me-1"></i>
                                                            {{ $point->reason_label }}
                                                        </span>
                                                        @break
                                                    @case('night_bonus')
                                                        <span class="badge bg-secondary">
                                                            <i class="fas fa-moon me-1"></i>
                                                            {{ $point->reason_label }}
                                                        </span>
                                                        @break
                                                    @case('medical_bonus')
                                                        <span class="badge bg-danger">
                                                            <i class="fas fa-heartbeat me-1"></i>
                                                            {{ $point->reason_label }}
                                                        </span>
                                                        @break
                                                    @case('achievement')
                                                        <span class="badge bg-primary">
                                                            <i class="fas fa-trophy me-1"></i>
                                                            {{ $point->reason_label }}
                                                        </span>
                                                        @break
                                                    @case('manual')
                                                        <span class="badge bg-info">
                                                            <i class="fas fa-gift me-1"></i>
                                                            {{ $point->reason_label }}
                                                        </span>
                                                        @break
                                                    @default
                                                        <span class="badge bg-secondary">
                                                            {{ $point->reason_label }}
                                                        </span>
                                                @endswitch
                                            </td>
                                            <td>{{ $point->description }}</td>
                                            <td class="text-end">
                                                <span class="fw-bold text-success">
                                                    +{{ number_format($point->points) }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="card-footer bg-white">
                            {{ $points->links() }}
                        </div>
                    @else
                        <div class="text-center py-5">
                            <i class="fas fa-coins fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Δεν έχεις κερδίσει πόντους ακόμα.</p>
                            <a href="{{ route('missions.index') }}" class="btn btn-primary">
                                <i class="fas fa-bullseye me-2"></i>
                                Βρες Αποστολές
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Back Links -->
    <div class="row mt-4">
        <div class="col-12 text-center">
            <a href="{{ route('gamification.leaderboard') }}" class="btn btn-outline-primary">
                <i class="fas fa-trophy me-2"></i>
                Κατάταξη
            </a>
            <a href="{{ route('gamification.achievements') }}" class="btn btn-outline-success ms-2">
                <i class="fas fa-medal me-2"></i>
                Επιτεύγματα
            </a>
        </div>
    </div>
</div>
@endsection
