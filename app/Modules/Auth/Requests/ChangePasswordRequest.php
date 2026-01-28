<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * Μηνύματα σφαλμάτων στα Ελληνικά.
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Ο τρέχων κωδικός είναι υποχρεωτικός.',
            'new_password.required' => 'Ο νέος κωδικός είναι υποχρεωτικός.',
            'new_password.min' => 'Ο νέος κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.',
            'new_password.confirmed' => 'Η επιβεβαίωση του νέου κωδικού δεν ταιριάζει.',
        ];
    }

    /**
     * Ονόματα πεδίων στα Ελληνικά.
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'τρέχων κωδικός',
            'new_password' => 'νέος κωδικός',
        ];
    }
}
