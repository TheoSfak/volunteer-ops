<?php

namespace App\Modules\Documents\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadFileRequest extends FormRequest
{
    /**
     * Καθορισμός αν ο χρήστης επιτρέπεται να κάνει αυτό το αίτημα.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Κανόνες επικύρωσης.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240', // 10MB
                'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif',
            ],
        ];
    }

    /**
     * Μηνύματα σφαλμάτων στα Ελληνικά.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Το αρχείο είναι υποχρεωτικό.',
            'file.file' => 'Πρέπει να ανεβάσετε ένα αρχείο.',
            'file.max' => 'Το αρχείο δεν μπορεί να υπερβαίνει τα 10MB.',
            'file.mimes' => 'Επιτρεπόμενοι τύποι: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, GIF.',
        ];
    }

    /**
     * Ονόματα πεδίων στα Ελληνικά.
     */
    public function attributes(): array
    {
        return [
            'file' => 'αρχείο',
        ];
    }
}
