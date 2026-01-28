<?php

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
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
            'category' => ['required', Rule::in(Document::CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'file_id' => ['required', 'exists:files,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'mission_id' => ['nullable', 'exists:missions,id'],
            'visibility' => ['sometimes', Rule::in(Document::VISIBILITIES)],
        ];
    }

    /**
     * Μηνύματα σφαλμάτων στα Ελληνικά.
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Η κατηγορία είναι υποχρεωτική.',
            'category.in' => 'Η κατηγορία πρέπει να είναι GENERAL, MISSION ή CERT.',
            'title.required' => 'Ο τίτλος είναι υποχρεωτικός.',
            'title.max' => 'Ο τίτλος δεν μπορεί να υπερβαίνει τους 255 χαρακτήρες.',
            'file_id.required' => 'Το αρχείο είναι υποχρεωτικό.',
            'file_id.exists' => 'Το επιλεγμένο αρχείο δεν υπάρχει.',
            'department_id.exists' => 'Το επιλεγμένο τμήμα δεν υπάρχει.',
            'mission_id.exists' => 'Η επιλεγμένη αποστολή δεν υπάρχει.',
            'visibility.in' => 'Η ορατότητα πρέπει να είναι PUBLIC, ADMINS ή PRIVATE.',
        ];
    }

    /**
     * Ονόματα πεδίων στα Ελληνικά.
     */
    public function attributes(): array
    {
        return [
            'category' => 'κατηγορία',
            'title' => 'τίτλος',
            'file_id' => 'αρχείο',
            'department_id' => 'τμήμα',
            'mission_id' => 'αποστολή',
            'visibility' => 'ορατότητα',
        ];
    }
}
