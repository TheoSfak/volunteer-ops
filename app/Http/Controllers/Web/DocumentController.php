<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    /**
     * Λίστα εγγράφων.
     */
    public function index()
    {
        $documents = Document::with('uploader')
            ->orderBy('created_at', 'desc')
            ->paginate(20);
            
        return view('documents.index', compact('documents'));
    }

    /**
     * Αποθήκευση νέου εγγράφου.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|max:10240',
            'title' => 'nullable|string|max:255',
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('documents', 'public');
            
            Document::create([
                'title' => $validated['title'] ?? $file->getClientOriginalName(),
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_by' => auth()->id(),
            ]);
        }

        return back()->with('success', 'Το έγγραφο ανέβηκε επιτυχώς.');
    }

    /**
     * Λήψη εγγράφου.
     */
    public function download(Document $document)
    {
        return Storage::disk('public')->download($document->file_path, $document->file_name);
    }

    /**
     * Διαγραφή εγγράφου.
     */
    public function destroy(Document $document)
    {
        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return back()->with('success', 'Το έγγραφο διαγράφηκε επιτυχώς.');
    }
}
