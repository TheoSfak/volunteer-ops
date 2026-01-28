<?php

namespace App\Modules\Directory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentRequest extends FormRequest
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
        $id = $this->route('department');
        
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:departments,code,' . $id],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'exists:departments,id'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Μηνύματα σφαλμάτων στα Ελληνικά.
     */
    public function messages(): array
    {
        return [
            'name.max' => 'Το όνομα τμήματος δεν μπορεί να υπερβαίνει τους 255 χαρακτήρες.',
            'code.unique' => 'Αυτός ο κωδικός τμήματος χρησιμοποιείται ήδη.',
            'code.max' => 'Ο κωδικός τμήματος δεν μπορεί να υπερβαίνει τους 50 χαρακτήρες.',
            'description.max' => 'Η περιγραφή δεν μπορεί να υπερβαίνει τους 1000 χαρακτήρες.',
            'parent_id.exists' => 'Το γονικό τμήμα δεν υπάρχει.',
        ];
    }

    /**
     * Ονόματα πεδίων στα Ελληνικά.
     */
    public function attributes(): array
    {
        return [
            'name' => 'όνομα τμήματος',
            'code' => 'κωδικός τμήματος',
            'description' => 'περιγραφή',
            'parent_id' => 'γονικό τμήμα',
            'is_active' => 'ενεργό',
        ];
    }
}
