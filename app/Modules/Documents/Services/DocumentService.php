<?php

namespace App\Modules\Documents\Services;

use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Support\Facades\Auth;

class DocumentService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Λήψη όλων των εγγράφων με φιλτράρισμα.
     */
    public function getAll(array $filters, User $user): array
    {
        $query = Document::with(['file', 'department', 'mission']);

        // Φιλτράρισμα ορατότητας βάσει ρόλου
        if (!$user->hasRole(User::ROLE_SYSTEM_ADMIN)) {
            $query->where(function ($q) use ($user) {
                $q->where('visibility', Document::VISIBILITY_PUBLIC);
                
                if ($user->isAdmin()) {
                    $q->orWhere('visibility', Document::VISIBILITY_ADMINS);
                }
                
                if ($user->department_id) {
                    $q->orWhere('department_id', $user->department_id);
                }
            });
        }

        // Φιλτράρισμα κατηγορίας
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        // Φιλτράρισμα αποστολής
        if (!empty($filters['mission_id'])) {
            $query->where('mission_id', $filters['mission_id']);
        }

        // Αναζήτηση τίτλου
        if (!empty($filters['search'])) {
            $query->where('title', 'like', '%' . $filters['search'] . '%');
        }

        $documents = $query->orderBy('created_at', 'desc')->get();

        return $documents->map(fn($doc) => $this->formatDocument($doc))->toArray();
    }

    /**
     * Λήψη εγγράφου με ID.
     */
    public function getById(int $id): ?array
    {
        $document = Document::with(['file', 'department', 'mission'])->find($id);

        if (!$document) {
            return null;
        }

        return $this->formatDocument($document);
    }

    /**
     * Δημιουργία εγγράφου.
     */
    public function create(array $data): array
    {
        $document = Document::create([
            'category' => $data['category'],
            'title' => $data['title'],
            'file_id' => $data['file_id'],
            'department_id' => $data['department_id'] ?? null,
            'mission_id' => $data['mission_id'] ?? null,
            'visibility' => $data['visibility'] ?? Document::VISIBILITY_PUBLIC,
        ]);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΔΗΜΙΟΥΡΓΙΑ_ΕΓΓΡΑΦΟΥ',
            entityType: 'Document',
            entityId: $document->id,
            after: $document->toArray()
        );

        return $this->formatDocument($document->load(['file', 'department', 'mission']));
    }

    /**
     * Διαγραφή εγγράφου.
     */
    public function delete(int $id): void
    {
        $document = Document::findOrFail($id);
        $before = $document->toArray();

        $document->delete();

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΔΙΑΓΡΑΦΗ_ΕΓΓΡΑΦΟΥ',
            entityType: 'Document',
            entityId: $id,
            before: $before
        );
    }

    /**
     * Μορφοποίηση εγγράφου για API.
     */
    protected function formatDocument(Document $document): array
    {
        return [
            'id' => $document->id,
            'τίτλος' => $document->title,
            'κατηγορία' => $document->category,
            'κατηγορία_ετικέτα' => $document->category_label,
            'ορατότητα' => $document->visibility,
            'ορατότητα_ετικέτα' => $document->visibility_label,
            'αρχείο' => $document->file ? [
                'id' => $document->file->id,
                'όνομα' => $document->file->filename,
                'τύπος' => $document->file->mime,
                'μέγεθος' => $document->file->human_size,
            ] : null,
            'τμήμα' => $document->department ? [
                'id' => $document->department->id,
                'όνομα' => $document->department->name,
            ] : null,
            'αποστολή' => $document->mission ? [
                'id' => $document->mission->id,
                'τίτλος' => $document->mission->title,
            ] : null,
            'δημιουργήθηκε' => $document->created_at,
        ];
    }
}
