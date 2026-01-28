<?php

namespace App\Modules\Documents\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\File;
use App\Modules\Documents\Services\FileService;
use App\Modules\Documents\Requests\UploadFileRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(
        protected FileService $fileService
    ) {}

    /**
     * Μεταφόρτωση αρχείου.
     */
    public function upload(UploadFileRequest $request): JsonResponse
    {
        $file = $this->fileService->upload($request->file('file'));

        return response()->json([
            'μήνυμα' => 'Το αρχείο ανέβηκε επιτυχώς.',
            'αρχείο' => $file,
        ], 201);
    }

    /**
     * Λήψη αρχείου.
     */
    public function download(int $id): StreamedResponse|JsonResponse
    {
        $file = File::find($id);

        if (!$file) {
            return response()->json([
                'μήνυμα' => 'Το αρχείο δεν βρέθηκε.',
            ], 404);
        }

        return $this->fileService->download($file);
    }
}
