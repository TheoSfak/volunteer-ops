@extends('layouts.app')

@section('title', 'Αρχείο Καταγραφών')
@section('page-title', 'Αρχείο Καταγραφών')

@section('content')
    <div class="card mb-4">
        <div class="card-body">
            <form action="{{ route('audit.index') }}" method="GET" class="row g-3">
                <div class="col-md-3">
                    <select class="form-select" name="action">
                        <option value="">Όλες οι ενέργειες</option>
                        <option value="create" {{ request('action') == 'create' ? 'selected' : '' }}>Δημιουργία</option>
                        <option value="update" {{ request('action') == 'update' ? 'selected' : '' }}>Ενημέρωση</option>
                        <option value="delete" {{ request('action') == 'delete' ? 'selected' : '' }}>Διαγραφή</option>
                        <option value="login" {{ request('action') == 'login' ? 'selected' : '' }}>Σύνδεση</option>
                        <option value="logout" {{ request('action') == 'logout' ? 'selected' : '' }}>Αποσύνδεση</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="entity_type">
                        <option value="">Όλα τα αντικείμενα</option>
                        <option value="Mission" {{ request('entity_type') == 'Mission' ? 'selected' : '' }}>Αποστολές</option>
                        <option value="Shift" {{ request('entity_type') == 'Shift' ? 'selected' : '' }}>Βάρδιες</option>
                        <option value="User" {{ request('entity_type') == 'User' ? 'selected' : '' }}>Χρήστες</option>
                        <option value="ParticipationRequest" {{ request('entity_type') == 'ParticipationRequest' ? 'selected' : '' }}>Συμμετοχές</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="from_date" value="{{ request('from_date') }}">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="to_date" value="{{ request('to_date') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search me-1"></i>Φίλτρο</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Ημ/νία</th>
                            <th>Χρήστης</th>
                            <th>Ενέργεια</th>
                            <th>Αντικείμενο</th>
                            <th>IP</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs ?? [] as $log)
                            <tr>
                                <td>{{ $log->created_at ? $log->created_at->format('d/m/Y H:i:s') : '-' }}</td>
                                <td>
                                    <div class="fw-medium">{{ $log->user->name ?? 'Σύστημα' }}</div>
                                    <small class="text-muted">{{ $log->user->email ?? '' }}</small>
                                </td>
                                <td>
                                    @php
                                        $actionBadge = ['create' => 'bg-success', 'update' => 'bg-info', 'delete' => 'bg-danger', 'login' => 'bg-primary', 'logout' => 'bg-secondary'];
                                        $actionLabel = ['create' => 'Δημιουργία', 'update' => 'Ενημέρωση', 'delete' => 'Διαγραφή', 'login' => 'Σύνδεση', 'logout' => 'Αποσύνδεση'];
                                    @endphp
                                    <span class="badge {{ $actionBadge[$log->action] ?? 'bg-secondary' }}">{{ $actionLabel[$log->action] ?? $log->action }}</span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark">{{ class_basename($log->entity_type) }}</span>
                                    <small class="text-muted">#{{ $log->entity_id }}</small>
                                </td>
                                <td><small class="text-muted">{{ $log->ip_address ?? '-' }}</small></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#detailModal{{ $log->id }}">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- Detail Modal -->
                            <div class="modal fade" id="detailModal{{ $log->id }}" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Λεπτομέρειες Καταγραφής</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <strong>Χρήστης:</strong> {{ $log->user->name ?? 'Σύστημα' }}
                                                </div>
                                                <div class="col-md-6">
                                                    <strong>Ημ/νία:</strong> {{ $log->created_at ? $log->created_at->format('d/m/Y H:i:s') : '-' }}
                                                </div>
                                            </div>
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <strong>Ενέργεια:</strong> {{ $log->action }}
                                                </div>
                                                <div class="col-md-6">
                                                    <strong>Αντικείμενο:</strong> {{ class_basename($log->entity_type) }} #{{ $log->entity_id }}
                                                </div>
                                            </div>
                                            @if($log->old_values)
                                                <div class="mb-3">
                                                    <strong>Προηγούμενες Τιμές:</strong>
                                                    <pre class="bg-light p-2 rounded mt-1" style="max-height: 200px; overflow: auto;">{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                </div>
                                            @endif
                                            @if($log->new_values)
                                                <div class="mb-3">
                                                    <strong>Νέες Τιμές:</strong>
                                                    <pre class="bg-light p-2 rounded mt-1" style="max-height: 200px; overflow: auto;">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-journal-text fs-1 d-block mb-2"></i>Δεν υπάρχουν καταγραφές</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if(isset($logs) && $logs->hasPages())
            <div class="card-footer">{{ $logs->links() }}</div>
        @endif
    </div>
@endsection
