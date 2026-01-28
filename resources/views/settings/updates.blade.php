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
                        <div class="alert alert-success mb-0">
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
                                <div class="release-notes">
                                    {!! nl2br(e($updateInfo['release_notes'])) !!}
                                </div>
                            @endif
                            
                            <hr>
                            <div class="d-flex flex-wrap gap-2">
                                @if($updateInfo['download_url'])
                                    <a href="{{ $updateInfo['download_url'] }}" class="btn btn-success" target="_blank">
                                        <i class="bi bi-download me-2"></i>Λήψη ZIP
                                        @if($updateInfo['download_size'])
                                            <small>({{ number_format($updateInfo['download_size'] / 1048576, 1) }} MB)</small>
                                        @endif
                                    </a>
                                @endif
                                @if($updateInfo['html_url'])
                                    <a href="{{ $updateInfo['html_url'] }}" class="btn btn-outline-primary" target="_blank">
                                        <i class="bi bi-github me-2"></i>Προβολή στο GitHub
                                    </a>
                                @endif
                            </div>
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

        <!-- Update Instructions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-book me-2"></i>Οδηγίες Ενημέρωσης</h5>
            </div>
            <div class="card-body">
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

@push('scripts')
<script>
document.getElementById('checkUpdatesBtn').addEventListener('click', function() {
    const btn = this;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Έλεγχος...';
    btn.disabled = true;
    
    fetch('{{ route('settings.check-updates') }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        // Reload page to show updated data
        window.location.reload();
    })
    .catch(error => {
        console.error('Error:', error);
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        alert('Σφάλμα κατά τον έλεγχο ενημερώσεων');
    });
});
</script>
@endpush
