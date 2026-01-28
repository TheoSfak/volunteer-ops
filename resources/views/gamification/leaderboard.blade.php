@extends('layouts.app')

@section('title', 'Κατάταξη Εθελοντών')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-0">
                <i class="fas fa-trophy text-warning me-2"></i>
                Κατάταξη Εθελοντών
            </h1>
            <p class="text-muted">Δες ποιοι εθελοντές έχουν συγκεντρώσει τους περισσότερους πόντους!</p>
        </div>
    </div>

    <!-- Period Selector -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="btn-group" role="group">
                <a href="{{ route('gamification.leaderboard', ['period' => 'all']) }}" 
                   class="btn {{ $period === 'all' ? 'btn-primary' : 'btn-outline-primary' }}">
                    <i class="fas fa-infinity me-1"></i> Συνολική
                </a>
                <a href="{{ route('gamification.leaderboard', ['period' => 'monthly']) }}" 
                   class="btn {{ $period === 'monthly' ? 'btn-primary' : 'btn-outline-primary' }}">
                    <i class="fas fa-calendar-alt me-1"></i> Μηνιαία
                </a>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Leaderboard -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-list-ol me-2"></i>
                        {{ $period === 'monthly' ? 'Μηνιαία Κατάταξη' : 'Συνολική Κατάταξη' }}
                    </h5>
                </div>
                <div class="card-body p-0">
                    @if(count($leaderboard) > 0)
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-center" style="width: 80px;">Θέση</th>
                                        <th>Εθελοντής</th>
                                        <th class="text-center">Πόντοι</th>
                                        <th class="text-center">Επιτεύγματα</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($leaderboard as $entry)
                                        <tr class="{{ $currentUser && $entry['user_id'] === $currentUser->id ? 'table-primary' : '' }}">
                                            <td class="text-center">
                                                @if($entry['rank'] === 1)
                                                    <span class="badge bg-warning text-dark fs-5">
                                                        <i class="fas fa-crown"></i> 1
                                                    </span>
                                                @elseif($entry['rank'] === 2)
                                                    <span class="badge bg-secondary fs-5">
                                                        <i class="fas fa-medal"></i> 2
                                                    </span>
                                                @elseif($entry['rank'] === 3)
                                                    <span class="badge bg-danger fs-5">
                                                        <i class="fas fa-award"></i> 3
                                                    </span>
                                                @else
                                                    <span class="text-muted fs-5">{{ $entry['rank'] }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                <strong>{{ $entry['name'] }}</strong>
                                                @if($currentUser && $entry['user_id'] === $currentUser->id)
                                                    <span class="badge bg-info ms-2">Εσύ</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold text-primary">{{ number_format($entry['points']) }}</span>
                                                <small class="text-muted">πόντοι</small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-success">
                                                    <i class="fas fa-trophy me-1"></i>
                                                    {{ $entry['achievements_count'] }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-5">
                            <i class="fas fa-trophy fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Δεν υπάρχουν δεδομένα κατάταξης ακόμα.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- User Stats Sidebar -->
        <div class="col-lg-4">
            @if($currentUser && $currentUser->volunteerProfile)
                <!-- Your Rank Card -->
                <div class="card shadow-sm mb-4 border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-user me-2"></i>
                            Η Θέση Σου
                        </h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="display-1 text-primary fw-bold mb-2">
                            #{{ $userRank }}
                        </div>
                        <p class="mb-3">
                            <span class="fs-4 fw-bold">{{ number_format($period === 'monthly' ? ($currentUser->monthly_points ?? 0) : ($currentUser->total_points ?? 0)) }}</span>
                            <span class="text-muted">πόντοι</span>
                        </p>
                        <a href="{{ route('gamification.achievements') }}" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-trophy me-1"></i> Δες τα Επιτεύγματά σου
                        </a>
                    </div>
                </div>

                <!-- Stats Card -->
                @if($userStats)
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-bar me-2"></i>
                            Τα Στατιστικά Σου
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="fs-4 fw-bold text-success">{{ number_format($userStats['total_hours'], 1) }}</div>
                                    <small class="text-muted">Ώρες</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="fs-4 fw-bold text-info">{{ $userStats['completed_shifts'] }}</div>
                                    <small class="text-muted">Βάρδιες</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="fs-4 fw-bold text-warning">{{ $userStats['weekend_shifts'] }}</div>
                                    <small class="text-muted">Σ/Κ</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="fs-4 fw-bold text-secondary">{{ $userStats['night_shifts'] }}</div>
                                    <small class="text-muted">Νυχτερινές</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            @else
                <div class="card shadow-sm">
                    <div class="card-body text-center py-4">
                        <i class="fas fa-user-plus fa-3x text-muted mb-3"></i>
                        <p class="text-muted">Συνδέσου ως εθελοντής για να δεις τη θέση σου!</p>
                    </div>
                </div>
            @endif

            <!-- Quick Links -->
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-link me-2"></i>
                        Γρήγορες Συνδέσεις
                    </h5>
                </div>
                <div class="list-group list-group-flush">
                    <a href="{{ route('gamification.achievements') }}" class="list-group-item list-group-item-action">
                        <i class="fas fa-trophy text-warning me-2"></i> Όλα τα Επιτεύγματα
                    </a>
                    <a href="{{ route('gamification.points-history') }}" class="list-group-item list-group-item-action">
                        <i class="fas fa-history text-info me-2"></i> Ιστορικό Πόντων
                    </a>
                    <a href="{{ route('missions.index') }}" class="list-group-item list-group-item-action">
                        <i class="fas fa-bullseye text-success me-2"></i> Διαθέσιμες Αποστολές
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
