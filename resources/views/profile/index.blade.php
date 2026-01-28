@extends('layouts.app')

@section('title', 'Το Προφίλ μου')
@section('page-title', 'Το Προφίλ μου')

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <!-- Profile Info -->
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-person me-2"></i>Στοιχεία Λογαριασμού</div>
                <div class="card-body">
                    <form action="{{ route('profile.update') }}" method="POST">
                        @csrf
                        @method('PUT')
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">Ονοματεπώνυμο</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $user->name) }}" required>
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email', $user->email) }}" required>
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="phone" class="form-label">Τηλέφωνο</label>
                            <input type="tel" class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone', $user->phone) }}">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Αποθήκευση</button>
                    </form>
                </div>
            </div>
            
            <!-- Change Password -->
            <div class="card">
                <div class="card-header"><i class="bi bi-lock me-2"></i>Αλλαγή Κωδικού</div>
                <div class="card-body">
                    <form action="{{ route('profile.password') }}" method="POST">
                        @csrf
                        @method('PUT')
                        
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Τρέχων Κωδικός</label>
                            <input type="password" class="form-control @error('current_password') is-invalid @enderror" id="current_password" name="current_password" required>
                            @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Νέος Κωδικός</label>
                                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="password_confirmation" class="form-label">Επιβεβαίωση Κωδικού</label>
                                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary"><i class="bi bi-key me-2"></i>Αλλαγή Κωδικού</button>
                    </form>
                </div>
            </div>
            
            <!-- Skills Section -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-award me-2"></i>Δεξιότητες & Διπλώματα</span>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#skillsModal">
                        <i class="bi bi-pencil me-1"></i>Επεξεργασία
                    </button>
                </div>
                <div class="card-body">
                    @php
                        $userSkills = $user->skills->groupBy('category');
                        $categoryLabels = \App\Models\Skill::CATEGORIES;
                    @endphp
                    
                    @if($user->skills->isEmpty())
                        <p class="text-muted mb-0">Δεν έχετε προσθέσει δεξιότητες ακόμα.</p>
                    @else
                        @foreach($categoryLabels as $category => $label)
                            @if(isset($userSkills[$category]) && $userSkills[$category]->count() > 0)
                                <h6 class="text-muted mb-2 mt-3 first:mt-0">{{ $label }}</h6>
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    @foreach($userSkills[$category] as $skill)
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary px-3 py-2">
                                            <i class="{{ $skill->icon ?? 'bi bi-check-circle' }} me-1"></i>
                                            {{ $skill->name }}
                                            @if($skill->pivot->details)
                                                <small class="ms-1">({{ $skill->pivot->details }})</small>
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <!-- Account Info -->
            <div class="card mb-4">
                <div class="card-body text-center">
                    <div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                        <span class="text-primary fs-1 fw-bold">{{ substr($user->name, 0, 1) }}</span>
                    </div>
                    <h5 class="mb-1">{{ $user->name }}</h5>
                    <p class="text-muted mb-2">{{ $user->email }}</p>
                    <span class="badge bg-primary">{{ $user->role ?? 'Χρήστης' }}</span>
                </div>
            </div>
            
            <!-- Stats -->
            <div class="card">
                <div class="card-header"><i class="bi bi-bar-chart me-2"></i>Στατιστικά</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Μέλος από</span>
                        <strong>{{ $user->created_at ? $user->created_at->format('d/m/Y') : '-' }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Τμήμα</span>
                        <strong>{{ $user->department->name ?? '-' }}</strong>
                    </div>
                    @if($user->volunteerProfile)
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Συμμετοχές</span>
                            <strong>{{ $user->participations->count() ?? 0 }}</strong>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Skills Modal -->
    <div class="modal fade" id="skillsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="{{ route('profile.skills') }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-award me-2"></i>Επεξεργασία Δεξιοτήτων</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        @php
                            $allSkills = \App\Models\Skill::active()->orderBy('sort_order')->get()->groupBy('category');
                            $userSkillIds = $user->skills->pluck('id')->toArray();
                        @endphp
                        
                        @foreach($categoryLabels as $category => $label)
                            @if(isset($allSkills[$category]))
                                <h6 class="mb-3 {{ !$loop->first ? 'mt-4' : '' }}">
                                    <i class="bi bi-folder me-1"></i>{{ $label }}
                                </h6>
                                <div class="row g-2">
                                    @foreach($allSkills[$category] as $skill)
                                        <div class="col-md-6">
                                            <div class="form-check border rounded p-3">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="skills[]" 
                                                       value="{{ $skill->id }}" 
                                                       id="skill_{{ $skill->id }}"
                                                       {{ in_array($skill->id, $userSkillIds) ? 'checked' : '' }}>
                                                <label class="form-check-label w-100" for="skill_{{ $skill->id }}">
                                                    <i class="{{ $skill->icon ?? 'bi bi-check-circle' }} me-1 text-primary"></i>
                                                    {{ $skill->name }}
                                                </label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Αποθήκευση</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
