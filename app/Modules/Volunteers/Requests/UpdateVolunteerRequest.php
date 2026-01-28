<?php

namespace App\Modules\Volunteers\Requests;

use App\Modules\Volunteers\Models\VolunteerProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVolunteerRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'rank' => ['sometimes', Rule::in(VolunteerProfile::RANKS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Μηνύματα σφαλμάτων στα Ελληνικά.
     */
    public function messages(): array
    {
        return [
            'name.max' => 'Το όνομα δεν μπορεί να υπερβαίνει τους 255 χαρακτήρες.',
            'phone.max' => 'Το τηλέφωνο δεν μπορεί να υπερβαίνει τους 20 χαρακτήρες.',
            'rank.in' => 'Ο βαθμός πρέπει να είναι DOKIMOS ή ENERGOS.',
            'notes.max' => 'Οι σημειώσεις δεν μπορούν να υπερβαίνουν τους 2000 χαρακτήρες.',
        ];
    }

    /**
     * Ονόματα πεδίων στα Ελληνικά.
     */
    public function attributes(): array
    {
        return [
            'name' => 'όνομα',
            'phone' => 'τηλέφωνο',
            'rank' => 'βαθμός',
            'notes' => 'σημειώσεις',
        ];
    }
}
