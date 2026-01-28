<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Μηνύματα σφαλμάτων στα Ελληνικά.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Το όνομα είναι υποχρεωτικό.',
            'name.max' => 'Το όνομα δεν μπορεί να υπερβαίνει τους 255 χαρακτήρες.',
            'email.required' => 'Το email είναι υποχρεωτικό.',
            'email.email' => 'Παρακαλώ εισάγετε έγκυρο email.',
            'email.unique' => 'Αυτό το email χρησιμοποιείται ήδη.',
            'password.required' => 'Ο κωδικός είναι υποχρεωτικός.',
            'password.min' => 'Ο κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.',
            'password.confirmed' => 'Η επιβεβαίωση κωδικού δεν ταιριάζει.',
            'phone.max' => 'Το τηλέφωνο δεν μπορεί να υπερβαίνει τους 20 χαρακτήρες.',
        ];
    }

    /**
     * Ονόματα πεδίων στα Ελληνικά.
     */
    public function attributes(): array
    {
        return [
            'name' => 'όνομα',
            'email' => 'email',
            'password' => 'κωδικός',
            'phone' => 'τηλέφωνο',
        ];
    }
}
