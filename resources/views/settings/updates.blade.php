@extends('layouts.app')

@section('title', 'Ενημερώσεις Συστήματος')
@section('page-title', 'Ενημερώσεις')

@section('content')
<div class="row">
    <div class="col-lg-8">
        <!-- Update Status Card -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-cloud-download me-2"></i>Κατάσταση Ενημερώσεων
                </h5>
                <button type="button" class="btn btn-outline-primary btn-sm" id="checkUpdatesBtn">
                    <i class="bi bi-arrow-clockwise me-1"></i>Έλεγχος
                </button>
            </div>
            <div class="card-body" id="updateStatusBody">
                @if($updateInfo['success'])
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Τρέχουσα Έκδοση</h6>
                            <h3 class="mb-0">
                                <span class="badge bg-primary fs-5">v{{ $updateInfo['current_version'] }}</span>
                            </h3>
                        </div>
                        <div class="col-md-6 text-md-end mt-3 mt-md-0">
                            <h6 class="text-muted mb-2">Τελευταία Διαθέσιμη</h6>
                            <h3 class="mb-0">
                                @if($updateInfo['has_update'])
                                    <span class="badge bg-success fs-5">v{{ $updateInfo['latest_version'] }}</span>
                                    <span class="badge bg-warning text-dark">Νέα!</span>
                                @else
                                    <span class="badge bg-secondary fs-5">v{{ $updateInfo['latest_version'] }}</span>
                                @endif
                            </h3>
                        </div>
                    </div>
                    
                    @if($updateInfo['has_update'])
                        <hr>
                        <div class="alert alert-success mb-3">
                            <h5 class="alert-heading">
                                <i class="bi bi-gift me-2"></i>{{ $updateInfo['release_name'] }}
                            </h5>
                            @if($updateInfo['release_date'])
                                <small class="text-muted">
                                    Δημοσιεύτηκε: {{ \Carbon\Carbon::parse($updateInfo['release_date'])->format('d/m/Y H:i') }}
                                </small>
                            @endif
                            
                            @if($updateInfo['release_notes'])
                                <hr>
                                <div class="release-notes" style="max-height: 200px; overflow-y: auto;">
                                    {!! nl2br(e($updateInfo['release_notes'])) !!}
                                </div>
                            @endif
                        </div>
                        
                        <!-- Auto Update Button -->
                        <button type="button" 
                                class="btn btn-success btn-lg w-100 mb-3" 
                                id="btnInstallUpdate"
                                data-version="{{ $updateInfo['latest_version'] }}"
                                data-url="{{ $updateInfo['download_url'] ?? '' }}">
                            <i class="bi bi-cloud-download me-2"></i>
                            Αυτόματη Εγκατάσταση v{{ $updateInfo['latest_version'] }}
                            @if($updateInfo['download_size'])
                                <small>({{ number_format($updateInfo['download_size'] / 1048576, 1) }} MB)</small>
                            @endif
                        </button>
                        
                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                            @if($updateInfo['download_url'])
                                <a href="{{ $updateInfo['download_url'] }}" class="btn btn-outline-secondary btn-sm" target="_blank">
                                    <i class="bi bi-download me-2"></i>Λήψη ZIP χειροκίνητα
                                </a>
                            @endif
                            @if($updateInfo['html_url'])
                                <a href="{{ $updateInfo['html_url'] }}" class="btn btn-outline-primary btn-sm" target="_blank">
                                    <i class="bi bi-github me-2"></i>GitHub
                                </a>
                            @endif
                        </div>
                    @else
                        <hr>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-check-circle me-2"></i>
                            <strong>Είστε ενημερωμένοι!</strong> Χρησιμοποιείτε την τελευταία έκδοση.
                        </div>
                    @endif
                @else
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        {{ $updateInfo['error'] ?? 'Αδυναμία ελέγχου ενημερώσεων' }}
                    </div>
                @endif
            </div>
        </div>

        <!-- Backups Section -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-archive me-2"></i>Διαθέσιμα Backups</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnRefreshBackups">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
            <div class="card-body p-0" id="backupsContainer">
                <div class="p-3 text-center text-muted">
                    <i class="bi bi-hourglass-split me-2"></i>Φόρτωση backups...
                </div>
            </div>
        </div>

        <!-- Maintenance Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-tools me-2"></i>Ενέργειες Συντήρησης</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-warning w-100" id="btnClearCache">
                            <i class="bi bi-trash me-2"></i>Καθαρισμός Cache
                        </button>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-info w-100" id="btnRunMigrations">
                            <i class="bi bi-database me-2"></i>Εκτέλεση Migrations
                        </button>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-secondary w-100" id="checkUpdatesBtn">
                            <i class="bi bi-arrow-clockwise me-2"></i>Έλεγχος Ενημερώσεων
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Update Instructions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-book me-2"></i>Οδηγίες Ενημέρωσης</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Αυτόματη Ενημέρωση:</strong> Πατήστε το κουμπί "Αυτόματη Εγκατάσταση" για να ενημερωθεί αυτόματα η εφαρμογή. 
                    Θα δημιουργηθεί αυτόματα backup πριν την ενημέρωση.
                </div>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Σημαντικό:</strong> Πριν την ενημέρωση, δημιουργήστε backup της βάσης δεδομένων και των αρχείων!
                </div>
                
                <h6>Βήματα Ενημέρωσης:</h6>
                <ol class="mb-0">
                    <li class="mb-2">
                        <strong>Backup:</strong> Εξάγετε τη βάση δεδομένων μέσω phpMyAdmin και κατεβάστε τον φάκελο <code>storage/</code>
                    </li>
                    <li class="mb-2">
                        <strong>Λήψη:</strong> Κατεβάστε το νέο ZIP από το κουμπί παραπάνω
                    </li>
                    <li class="mb-2">
                        <strong>Αποσυμπίεση:</strong> Αποσυμπιέστε τα αρχεία σε νέο φάκελο
                    </li>
                    <li class="mb-2">
                        <strong>Αντικατάσταση:</strong> Αντικαταστήστε τα αρχεία στον server (εκτός από <code>.env</code> και <code>storage/</code>)
                    </li>
                    <li class="mb-2">
                        <strong>Migrations:</strong> Αν έχετε SSH, τρέξτε: <code>php artisan migrate</code>
                    </li>
                    <li class="mb-2">
                        <strong>Cache:</strong> Καθαρίστε την cache: <code>php artisan cache:clear</code>
                    </li>
                </ol>
            </div>
        </div>

        <!-- Release History -->
        @if($allReleases['success'] && !empty($allReleases['releases']))
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Ιστορικό Εκδόσεων</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    @foreach($allReleases['releases'] as $release)
                        <a href="{{ $release['url'] }}" class="list-group-item list-group-item-action" target="_blank">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>v{{ $release['version'] }}</strong>
                                    @if($release['version'] === $updateInfo['current_version'])
                                        <span class="badge bg-primary ms-2">Τρέχουσα</span>
                                    @endif
                                    <br>
                                    <small class="text-muted">{{ $release['name'] }}</small>
                                </div>
                                <div class="text-end">
                                    @if($release['date'])
                                        <small class="text-muted">
                                            {{ \Carbon\Carbon::parse($release['date'])->format('d/m/Y') }}
                                        </small>
                                    @endif
                                    <br>
                                    <i class="bi bi-box-arrow-up-right text-muted"></i>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
    </div>

    <!-- System Info Sidebar -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Πληροφορίες Συστήματος</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        @foreach($systemInfo as $key => $value)
                            <tr>
                                <td class="text-muted">
                                    {{ str_replace('_', ' ', ucfirst($key)) }}
                                </td>
                                <td class="text-end">
                                    <code>{{ $value }}</code>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-link-45deg me-2"></i>Γρήγοροι Σύνδεσμοι</h5>
            </div>
            <div class="list-group list-group-flush">
                <a href="https://github.com/TheoSfak/volunteer-ops" class="list-group-item list-group-item-action" target="_blank">
                    <i class="bi bi-github me-2"></i>GitHub Repository
                </a>
                <a href="https://github.com/TheoSfak/volunteer-ops/releases" class="list-group-item list-group-item-action" target="_blank">
                    <i class="bi bi-tag me-2"></i>Όλες οι Εκδόσεις
                </a>
                <a href="https://github.com/TheoSfak/volunteer-ops/issues" class="list-group-item list-group-item-action" target="_blank">
                    <i class="bi bi-bug me-2"></i>Αναφορά Σφαλμάτων
                </a>
                <a href="{{ route('settings.index') }}" class="list-group-item list-group-item-action">
                    <i class="bi bi-gear me-2"></i>Πίσω στις Ρυθμίσεις
                </a>
            </div>
        </div>
    </div>
</div>
@endsection

<!-- Update Progress Modal -->
<div class="modal fade" id="updateProgressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2 spin"></i>Ενημέρωση σε εξέλιξη...</h5>
            </div>
            <div class="modal-body">
                <div id="updateSteps">
                    <div class="update-step" data-step="backup">
                        <i class="bi bi-circle step-icon"></i>
                        <span class="step-text">Δημιουργία backup...</span>
                    </div>
                    <div class="update-step" data-step="download">
                        <i class="bi bi-circle step-icon"></i>
                        <span class="step-text">Λήψη νέας έκδοσης...</span>
                    </div>
                    <div class="update-step" data-step="apply">
                        <i class="bi bi-circle step-icon"></i>
                        <span class="step-text">Εφαρμογή ενημέρωσης...</span>
                    </div>
                    <div class="update-step" data-step="migrate">
                        <i class="bi bi-circle step-icon"></i>
                        <span class="step-text">Εκτέλεση migrations...</span>
                    </div>
                    <div class="update-step" data-step="cache">
                        <i class="bi bi-circle step-icon"></i>
                        <span class="step-text">Καθαρισμός cache...</span>
                    </div>
                </div>
                <div id="updateResult" class="mt-3 d-none"></div>
            </div>
            <div class="modal-footer d-none" id="updateModalFooter">
                <button type="button" class="btn btn-primary" onclick="window.location.reload()">
                    <i class="bi bi-arrow-clockwise me-2"></i>Ανανέωση Σελίδας
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
.update-step {
    padding: 8px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #6c757d;
}
.update-step.active {
    color: #0d6efd;
    font-weight: 500;
}
.update-step.completed {
    color: #198754;
}
.update-step.failed {
    color: #dc3545;
}
.update-step .step-icon {
    font-size: 1.2rem;
}
</style>

@push('scripts')
<script>
const csrf = '{{ csrf_token() }}';

// Load backups on page load
document.addEventListener('DOMContentLoaded', function() {
    loadBackups();
});

// Check Updates Button
document.getElementById('checkUpdatesBtn')?.addEventListener('click', function() {
    const btn = this;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Έλεγχος...';
    btn.disabled = true;
    
    fetch('{{ route('updates.check') }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        window.location.reload();
    })
    .catch(error => {
        console.error('Error:', error);
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        alert('Σφάλμα κατά τον έλεγχο ενημερώσεων');
    });
});

// Install Update Button
document.getElementById('btnInstallUpdate')?.addEventListener('click', function() {
    if (!confirm('Θέλετε να εγκαταστήσετε την ενημέρωση;\n\nΘα δημιουργηθεί αυτόματα backup πριν την εγκατάσταση.')) {
        return;
    }
    
    const modal = new bootstrap.Modal(document.getElementById('updateProgressModal'));
    modal.show();
    
    startUpdateProcess();
});

async function startUpdateProcess() {
    const steps = ['backup', 'download', 'apply', 'migrate', 'cache'];
    let currentStep = 0;
    
    try {
        // Call the install endpoint
        setStepActive('backup');
        
        const response = await fetch('{{ route('updates.install') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Mark all steps as completed
            steps.forEach(step => setStepCompleted(step));
            
            document.getElementById('updateResult').innerHTML = `
                <div class="alert alert-success mb-0">
                    <i class="bi bi-check-circle me-2"></i>
                    <strong>Η ενημέρωση ολοκληρώθηκε επιτυχώς!</strong><br>
                    ${data.message || ''}
                    ${data.version ? '<br>Νέα έκδοση: <strong>v' + data.version + '</strong>' : ''}
                </div>
            `;
            document.getElementById('updateResult').classList.remove('d-none');
            document.getElementById('updateModalFooter').classList.remove('d-none');
            document.querySelector('#updateProgressModal .modal-title').innerHTML = 
                '<i class="bi bi-check-circle-fill text-success me-2"></i>Ενημέρωση Ολοκληρώθηκε';
        } else {
            throw new Error(data.error || 'Άγνωστο σφάλμα');
        }
    } catch (error) {
        console.error('Update error:', error);
        
        document.getElementById('updateResult').innerHTML = `
            <div class="alert alert-danger mb-0">
                <i class="bi bi-x-circle me-2"></i>
                <strong>Σφάλμα κατά την ενημέρωση</strong><br>
                ${error.message}
                <hr>
                <small>Μπορείτε να επαναφέρετε από τα backups αν χρειαστεί.</small>
            </div>
        `;
        document.getElementById('updateResult').classList.remove('d-none');
        document.getElementById('updateModalFooter').classList.remove('d-none');
        document.querySelector('#updateProgressModal .modal-title').innerHTML = 
            '<i class="bi bi-x-circle-fill text-danger me-2"></i>Αποτυχία Ενημέρωσης';
    }
}

function setStepActive(stepName) {
    const step = document.querySelector(`.update-step[data-step="${stepName}"]`);
    if (step) {
        step.classList.add('active');
        step.querySelector('.step-icon').className = 'bi bi-arrow-repeat step-icon spin';
    }
}

function setStepCompleted(stepName) {
    const step = document.querySelector(`.update-step[data-step="${stepName}"]`);
    if (step) {
        step.classList.remove('active');
        step.classList.add('completed');
        step.querySelector('.step-icon').className = 'bi bi-check-circle-fill step-icon';
    }
}

function setStepFailed(stepName) {
    const step = document.querySelector(`.update-step[data-step="${stepName}"]`);
    if (step) {
        step.classList.remove('active');
        step.classList.add('failed');
        step.querySelector('.step-icon').className = 'bi bi-x-circle-fill step-icon';
    }
}

// Clear Cache Button
document.getElementById('btnClearCache')?.addEventListener('click', async function() {
    const btn = this;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Καθαρισμός...';
    btn.disabled = true;
    
    try {
        const response = await fetch('{{ route('updates.clear-cache') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✓ ' + data.message);
        } else {
            alert('✗ ' + (data.error || 'Σφάλμα'));
        }
    } catch (error) {
        alert('✗ Σφάλμα: ' + error.message);
    } finally {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    }
});

// Run Migrations Button
document.getElementById('btnRunMigrations')?.addEventListener('click', async function() {
    if (!confirm('Θέλετε να εκτελέσετε τα pending migrations;')) {
        return;
    }
    
    const btn = this;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Εκτέλεση...';
    btn.disabled = true;
    
    try {
        const response = await fetch('{{ route('updates.migrate') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✓ ' + data.message);
        } else {
            alert('✗ ' + (data.error || 'Σφάλμα'));
        }
    } catch (error) {
        alert('✗ Σφάλμα: ' + error.message);
    } finally {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    }
});

// Refresh Backups Button
document.getElementById('btnRefreshBackups')?.addEventListener('click', loadBackups);

// Load Backups
async function loadBackups() {
    const container = document.getElementById('backupsContainer');
    container.innerHTML = '<div class="p-3 text-center text-muted"><i class="bi bi-hourglass-split me-2"></i>Φόρτωση backups...</div>';
    
    try {
        const response = await fetch('{{ route('updates.backups') }}', {
            headers: {
                'Accept': 'application/json',
            }
        });
        
        const data = await response.json();
        
        if (data.success && data.backups && data.backups.length > 0) {
            let html = '<div class="list-group list-group-flush">';
            data.backups.forEach(backup => {
                html += `
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-archive me-2"></i>
                            <strong>${backup.name}</strong>
                            <span class="badge bg-secondary ms-2">v${backup.version}</span><br>
                            <small class="text-muted">
                                ${backup.date} • ${backup.size}
                            </small>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-warning" 
                                onclick="restoreBackup('${backup.name}')">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Επαναφορά
                        </button>
                    </div>
                `;
            });
            html += '</div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div class="p-3 text-center text-muted"><i class="bi bi-inbox me-2"></i>Δεν υπάρχουν διαθέσιμα backups</div>';
        }
    } catch (error) {
        container.innerHTML = '<div class="p-3 text-center text-danger"><i class="bi bi-x-circle me-2"></i>Σφάλμα φόρτωσης backups</div>';
    }
}

// Restore Backup
async function restoreBackup(backupName) {
    if (!confirm('Θέλετε να επαναφέρετε αυτό το backup;\n\nΠΡΟΣΟΧΗ: Τα τρέχοντα αρχεία θα αντικατασταθούν!')) {
        return;
    }
    
    try {
        const response = await fetch('{{ route('updates.rollback') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ backup_name: backupName })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✓ ' + data.message);
            window.location.reload();
        } else {
            alert('✗ ' + (data.error || data.message || 'Σφάλμα'));
        }
    } catch (error) {
        alert('✗ Σφάλμα: ' + error.message);
    }
}
</script>
@endpush
