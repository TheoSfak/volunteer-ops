<?php

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Models\File;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Μεταφόρτωση αρχείου.
     */
    public function upload(UploadedFile $uploadedFile): array
    {
        $path = $uploadedFile->store('uploads', 'local');
        
        $file = File::create([
            'path' => $path,
            'filename' => $uploadedFile->getClientOriginalName(),
            'mime' => $uploadedFile->getMimeType(),
            'size' => $uploadedFile->getSize(),
            'uploaded_by' => Auth::id(),
        ]);

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΜΕΤΑΦΟΡΤΩΣΗ_ΑΡΧΕΙΟΥ',
            entityType: 'File',
            entityId: $file->id,
            after: $file->toArray()
        );

        return $this->formatFile($file);
    }

    /**
     * Λήψη αρχείου.
     */
    public function download(File $file): StreamedResponse
    {
        return Storage::disk('local')->download(
            $file->path,
            $file->filename
        );
    }

    /**
     * Διαγραφή αρχείου.
     */
    public function delete(int $id): void
    {
        $file = File::findOrFail($id);
        
        // Διαγραφή από αποθηκευτικό χώρο
        Storage::disk('local')->delete($file->path);
        
        $before = $file->toArray();
        $file->delete();

        $this->auditService->log(
            actor: Auth::user(),
            action: 'ΔΙΑΓΡΑΦΗ_ΑΡΧΕΙΟΥ',
            entityType: 'File',
            entityId: $id,
            before: $before
        );
    }

    /**
     * Μορφοποίηση αρχείου για API.
     */
    protected function formatFile(File $file): array
    {
        return [
            'id' => $file->id,
            'όνομα_αρχείου' => $file->filename,
            'τύπος' => $file->mime,
            'μέγεθος' => $file->human_size,
            'μέγεθος_bytes' => $file->size,
            'ανέβηκε' => $file->created_at,
        ];
    }
}
