@extends('layouts.app')

@section('title', 'Κατάταξη Εθελοντών')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-start">
            <div>
                <h1 class="h3 mb-0">
                    <i class="bi bi-trophy text-warning me-2"></i>
                    Κατάταξη Εθελοντών {{ $selectedYear ?? now()->year }}
                </h1>
                <p class="text-muted">Δες ποιοι εθελοντές έχουν συγκεντρώσει τους περισσότερους πόντους!</p>
            </div>
            {{-- Year Selector --}}
            @if(!empty($availableYears) && count($availableYears) > 1)
            <div class="dropdown">
                <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-calendar-range me-1"></i>{{ $selectedYear ?? now()->year }}
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    @foreach($availableYears as $year)
                        <li>
                            <a class="dropdown-item {{ ($selectedYear ?? now()->year) == $year ? 'active' : '' }}" 
                               href="{{ route('gamification.leaderboard', ['year' => $year, 'period' => $period]) }}">
                                {{ $year }}
                                @if($year == ($currentYear ?? now()->year))
                                    <span class="badge bg-primary ms-2">Τρέχον</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>
    </div>

    <!-- Period Selector -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="btn-group" role="group">
                <a href="{{ route('gamification.leaderboard', ['period' => 'yearly', 'year' => $selectedYear]) }}" 
                   class="btn {{ $period === 'yearly' ? 'btn-primary' : 'btn-outline-primary' }}">
                    <i class="bi bi-calendar me-1"></i> Ετήσια
                </a>
                <a href="{{ route('gamification.leaderboard', ['period' => 'monthly', 'year' => $selectedYear]) }}" 
                   class="btn {{ $period === 'monthly' ? 'btn-primary' : 'btn-outline-primary' }}">
                    <i class="bi bi-calendar-month me-1"></i> Μηνιαία
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
                        <i class="bi bi-list-ol me-2"></i>
                        {{ $period === 'monthly' ? 'Μηνιαία Κατάταξη' : 'Ετήσια Κατάταξη' }} {{ $selectedYear ?? now()->year }}
                        @if(($selectedYear ?? now()->year) !== ($currentYear ?? now()->year))
                            <span class="badge bg-secondary ms-2">Ιστορικό</span>
                        @endif
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
                                        <th class="text-center">Ώρες</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($leaderboard as $index => $entry)
                                        @php $rank = $index + 1; @endphp
                                        <tr class="{{ $currentUser && ($entry['id'] ?? null) === $currentUser->id ? 'table-primary' : '' }}">
                                            <td class="text-center">
                                                @if($rank === 1)
                                                    <span class="badge bg-warning text-dark fs-5">
                                                        <i class="bi bi-trophy-fill"></i> 1
                                                    </span>
                                                @elseif($rank === 2)
                                                    <span class="badge bg-secondary fs-5">
                                                        <i class="bi bi-award-fill"></i> 2
                                                    </span>
                                                @elseif($rank === 3)
                                                    <span class="badge bg-danger fs-5">
                                                        <i class="bi bi-award"></i> 3
                                                    </span>
                                                @else
                                                    <span class="text-muted fs-5">{{ $rank }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                <strong>{{ $entry['name'] ?? 'Άγνωστος' }}</strong>
                                                @if($currentUser && ($entry['id'] ?? null) === $currentUser->id)
                                                    <span class="badge bg-info ms-2">Εσύ</span>
                                                @endif
                                                @if(!empty($entry['department']))
                                                    <br><small class="text-muted">{{ $entry['department'] }}</small>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold text-primary">{{ number_format($entry['points'] ?? 0) }}</span>
                                                <small class="text-muted">πόντοι</small>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold">{{ number_format($entry['hours'] ?? 0, 1) }}</span>
                                                <small class="text-muted">ώρες</small>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-5">
                            <i class="bi bi-trophy fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Δεν υπάρχουν δεδομένα κατάταξης για το {{ $selectedYear ?? now()->year }}.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- User Stats Sidebar -->
        <div class="col-lg-4">
            @if($currentUser)
                <!-- Your Rank Card -->
                <div class="card shadow-sm mb-4 border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-person me-2"></i>
                            Η Θέση Σου ({{ $selectedYear ?? now()->year }})
                        </h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="display-1 text-primary fw-bold mb-2">
                            #{{ $userRank ?? '-' }}
                        </div>
                        <p class="mb-3">
                        <p class="mb-3">
                            <span class="fs-4 fw-bold">{{ number_format($userStats['yearly_points'] ?? ($currentUser->total_points ?? 0)) }}</span>
                            <span class="text-muted">πόντοι</span>
                        </p>
                        <p class="mb-3">
                            <span class="fs-5">{{ number_format($userStats['yearly_hours'] ?? 0, 1) }}</span>
                            <span class="text-muted">ώρες</span>
                        </p>
                        <a href="{{ route('gamification.achievements') }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-trophy me-1"></i> Δες τα Επιτεύγματά σου
                        </a>
                    </div>
                </div>

                <!-- Stats Card -->
                @if($userStats)
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="bi bi-bar-chart me-2"></i>
                            Τα Στατιστικά Σου
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="fs-4 fw-bold text-success">{{ number_format($userStats['yearly_hours'] ?? $userStats['total_hours'] ?? 0, 1) }}</div>
                                    <small class="text-muted">Ώρες {{ $selectedYear ?? now()->year }}</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="fs-4 fw-bold text-info">{{ $userStats['completed_shifts'] ?? 0 }}</div>
                                    <small class="text-muted">Βάρδιες</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="fs-4 fw-bold text-warning">{{ $userStats['weekend_shifts'] ?? 0 }}</div>
                                    <small class="text-muted">Σ/Κ</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="fs-4 fw-bold text-secondary">{{ $userStats['night_shifts'] ?? 0 }}</div>
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
                        <i class="bi bi-person-plus fa-3x text-muted mb-3"></i>
                        <p class="text-muted">Συνδέσου ως εθελοντής για να δεις τη θέση σου!</p>
                    </div>
                </div>
            @endif

            <!-- Quick Links -->
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="bi bi-link me-2"></i>
                        Γρήγορες Συνδέσεις
                    </h5>
                </div>
                <div class="list-group list-group-flush">
                    <a href="{{ route('gamification.achievements') }}" class="list-group-item list-group-item-action">
                        <i class="bi bi-trophy text-warning me-2"></i> Όλα τα Επιτεύγματα
                    </a>
                    <a href="{{ route('gamification.points-history') }}" class="list-group-item list-group-item-action">
                        <i class="bi bi-clock-history text-info me-2"></i> Ιστορικό Πόντων
                    </a>
                    <a href="{{ route('missions.index') }}" class="list-group-item list-group-item-action">
                        <i class="bi bi-bullseye text-success me-2"></i> Διαθέσιμες Αποστολές
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection