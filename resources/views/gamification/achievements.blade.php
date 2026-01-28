@extends('layouts.app')

@section('title', 'Επιτεύγματα')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-0">
                <i class="fas fa-trophy text-warning me-2"></i>
                Επιτεύγματα & Διακρίσεις
            </h1>
            <p class="text-muted">Κατάκτησε επιτεύγματα μέσω της εθελοντικής σου δράσης!</p>
        </div>
    </div>

    <!-- Stats Overview -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card bg-primary text-white h-100">
                <div class="card-body text-center">
                    <i class="fas fa-star fa-2x mb-2"></i>
                    <h3 class="mb-0">{{ count($earnedAchievementIds) }}</h3>
                    <small>Κερδισμένα Επιτεύγματα</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card bg-success text-white h-100">
                <div class="card-body text-center">
                    <i class="fas fa-clock fa-2x mb-2"></i>
                    <h3 class="mb-0">{{ number_format($userStats['total_hours'], 1) }}</h3>
                    <small>Ώρες Εθελοντισμού</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card bg-info text-white h-100">
                <div class="card-body text-center">
                    <i class="fas fa-calendar-check fa-2x mb-2"></i>
                    <h3 class="mb-0">{{ $userStats['completed_shifts'] }}</h3>
                    <small>Ολοκληρωμένες Βάρδιες</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card bg-warning text-dark h-100">
                <div class="card-body text-center">
                    <i class="fas fa-coins fa-2x mb-2"></i>
                    <h3 class="mb-0">{{ number_format(auth()->user()->total_points ?? 0) }}</h3>
                    <small>Συνολικοί Πόντοι</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Next Achievements (Progress) -->
    @if(count($nextAchievements) > 0)
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-rocket text-info me-2"></i>
                        Επόμενα Επιτεύγματα
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach($nextAchievements as $next)
                            <div class="col-md-4 col-lg-3 mb-3">
                                <div class="card h-100 border-{{ $next['achievement']->color }}">
                                    <div class="card-body text-center">
                                        <div class="mb-2">
                                            <i class="{{ $next['achievement']->icon }} fa-2x text-{{ $next['achievement']->color }}"></i>
                                        </div>
                                        <h6 class="card-title">{{ $next['achievement']->name }}</h6>
                                        <div class="progress mb-2" style="height: 10px;">
                                            <div class="progress-bar bg-{{ $next['achievement']->color }}" 
                                                 role="progressbar" 
                                                 style="width: {{ $next['progress'] }}%"
                                                 aria-valuenow="{{ $next['progress'] }}" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            {{ $next['current'] }} / {{ $next['target'] }}
                                            ({{ number_format($next['progress'], 0) }}%)
                                        </small>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    <!-- Achievements by Category -->
    @foreach($achievementsByCategory as $category => $achievements)
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        @switch($category)
                            @case('hours')
                                <i class="fas fa-clock text-info me-2"></i>
                                @break
                            @case('shifts')
                                <i class="fas fa-calendar-check text-success me-2"></i>
                                @break
                            @case('streak')
                                <i class="fas fa-fire text-danger me-2"></i>
                                @break
                            @case('special')
                                <i class="fas fa-star text-warning me-2"></i>
                                @break
                            @case('milestone')
                                <i class="fas fa-flag-checkered text-primary me-2"></i>
                                @break
                            @default
                                <i class="fas fa-trophy text-secondary me-2"></i>
                        @endswitch
                        {{ \App\Models\Achievement::CATEGORY_LABELS[$category] ?? $category }}
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach($achievements as $achievement)
                            @php
                                $isEarned = in_array($achievement->id, $earnedAchievementIds);
                            @endphp
                            <div class="col-md-4 col-lg-3 mb-3">
                                <div class="card h-100 {{ $isEarned ? 'border-success' : 'border-secondary opacity-50' }}">
                                    <div class="card-body text-center position-relative">
                                        @if($isEarned)
                                            <span class="position-absolute top-0 end-0 mt-2 me-2">
                                                <i class="fas fa-check-circle text-success"></i>
                                            </span>
                                        @endif
                                        <div class="mb-2">
                                            <span class="badge rounded-circle p-3 bg-{{ $achievement->color }} {{ $isEarned ? '' : 'bg-opacity-25' }}">
                                                <i class="{{ $achievement->icon }} fa-2x {{ $isEarned ? 'text-white' : 'text-muted' }}"></i>
                                            </span>
                                        </div>
                                        <h6 class="card-title {{ $isEarned ? '' : 'text-muted' }}">
                                            {{ $achievement->name }}
                                        </h6>
                                        <p class="card-text small text-muted mb-2">
                                            {{ $achievement->description }}
                                        </p>
                                        <div>
                                            <span class="badge bg-{{ $isEarned ? 'success' : 'secondary' }}">
                                                <i class="fas fa-coins me-1"></i>
                                                +{{ $achievement->points_reward }} πόντοι
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endforeach

    <!-- Back to Leaderboard -->
    <div class="row">
        <div class="col-12 text-center">
            <a href="{{ route('gamification.leaderboard') }}" class="btn btn-outline-primary">
                <i class="fas fa-trophy me-2"></i>
                Δες την Κατάταξη
            </a>
            <a href="{{ route('gamification.points-history') }}" class="btn btn-outline-secondary ms-2">
                <i class="fas fa-history me-2"></i>
                Ιστορικό Πόντων
            </a>
        </div>
    </div>
</div>
@endsection
