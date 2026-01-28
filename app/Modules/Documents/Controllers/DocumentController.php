<?php

namespace App\Modules\Documents\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Requests\StoreDocumentRequest;
use App\Modules\Documents\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentService $documentService
    ) {}

    /**
     * Λίστα εγγράφων.
     */
    public function index(Request $request): JsonResponse
    {
        $documents = $this->documentService->getAll($request->all(), $request->user());

        return response()->json([
            'έγγραφα' => $documents,
        ]);
    }

    /**
     * Δημιουργία εγγράφου.
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $this->authorize('create', Document::class);

        $document = $this->documentService->create($request->validated());

        return response()->json([
            'μήνυμα' => 'Το έγγραφο δημιουργήθηκε επιτυχώς.',
            'έγγραφο' => $document,
        ], 201);
    }

    /**
     * Προβολή εγγράφου.
     */
    public function show(int $id): JsonResponse
    {
        $document = $this->documentService->getById($id);

        if (!$document) {
            return response()->json([
                'μήνυμα' => 'Το έγγραφο δεν βρέθηκε.',
            ], 404);
        }

        return response()->json([
            'έγγραφο' => $document,
        ]);
    }

    /**
     * Διαγραφή εγγράφου.
     */
    public function destroy(int $id): JsonResponse
    {
        $document = Document::findOrFail($id);
        $this->authorize('delete', $document);

        $this->documentService->delete($id);

        return response()->json([
            'μήνυμα' => 'Το έγγραφο διαγράφηκε επιτυχώς.',
        ]);
    }
}
