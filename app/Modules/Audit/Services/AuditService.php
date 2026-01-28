<?php

namespace App\Modules\Audit\Services;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;

class AuditService
{
    /**
     * Καταγραφή ενέργειας στο audit log.
     */
    public function log(
        ?User $actor,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $before ? json_encode($before) : null,
            'new_values' => $after ? json_encode($after) : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Λήψη ιστορικού με φίλτρα.
     */
    public function getHistory(array $filters = []): array
    {
        $query = AuditLog::with('actor')
            ->orderBy('created_at', 'desc');

        if (!empty($filters['action'])) {
            $query->forAction($filters['action']);
        }

        if (!empty($filters['entity_type'])) {
            $query->forEntity($filters['entity_type'], $filters['entity_id'] ?? null);
        }

        if (!empty($filters['actor_id'])) {
            $query->byActor($filters['actor_id']);
        }

        if (!empty($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        $perPage = $filters['per_page'] ?? 50;
        $logs = $query->paginate($perPage);

        return [
            'data' => $logs->getCollection()->map(fn($log) => $this->formatLog($log))->toArray(),
            'total' => $logs->total(),
            'current_page' => $logs->currentPage(),
            'per_page' => $logs->perPage(),
        ];
    }

    /**
     * Λήψη ιστορικού για συγκεκριμένη οντότητα.
     */
    public function getEntityHistory(string $entityType, int $entityId): array
    {
        $logs = AuditLog::with('actor')
            ->forEntity($entityType, $entityId)
            ->orderBy('created_at', 'desc')
            ->get();

        return $logs->map(fn($log) => $this->formatLog($log))->toArray();
    }

    /**
     * Μορφοποίηση log για API.
     */
    protected function formatLog(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'ενέργεια' => $log->action,
            'ενέργεια_ετικέτα' => $log->action_label,
            'τύπος_οντότητας' => $log->entity_type,
            'id_οντότητας' => $log->entity_id,
            'χρήστης' => $log->actor ? [
                'id' => $log->actor->id,
                'όνομα' => $log->actor->name,
            ] : null,
            'πριν' => $log->before_json,
            'μετά' => $log->after_json,
            'ημερομηνία' => $log->created_at,
        ];
    }
}
