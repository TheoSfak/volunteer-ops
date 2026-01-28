<?php

namespace App\Modules\Shifts\Requests;

use App\Modules\Shifts\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShiftRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'start_dt' => ['sometimes', 'date'],
            'end_dt' => ['sometimes', 'date', 'after:start_dt'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'leader_user_id' => ['nullable', 'exists:users,id'],
            'status' => ['sometimes', Rule::in(Shift::STATUSES)],
        ];
    }

    /**
     * Μηνύματα σφαλμάτων στα Ελληνικά.
     */
    public function messages(): array
    {
        return [
            'title.max' => 'Ο τίτλος βάρδιας δεν μπορεί να υπερβαίνει τους 255 χαρακτήρες.',
            'start_dt.date' => 'Η ημερομηνία/ώρα έναρξης δεν είναι έγκυρη.',
            'end_dt.date' => 'Η ημερομηνία/ώρα λήξης δεν είναι έγκυρη.',
            'end_dt.after' => 'Η λήξη πρέπει να είναι μετά την έναρξη.',
            'capacity.min' => 'Η χωρητικότητα πρέπει να είναι τουλάχιστον 1.',
            'capacity.max' => 'Η χωρητικότητα δεν μπορεί να υπερβαίνει τα 1000.',
            'leader_user_id.exists' => 'Ο επιλεγμένος αρχηγός δεν υπάρχει.',
            'status.in' => 'Η κατάσταση πρέπει να είναι OPEN, FULL, LOCKED ή CANCELED.',
        ];
    }

    /**
     * Ονόματα πεδίων στα Ελληνικά.
     */
    public function attributes(): array
    {
        return [
            'title' => 'τίτλος βάρδιας',
            'start_dt' => 'έναρξη',
            'end_dt' => 'λήξη',
            'capacity' => 'χωρητικότητα',
            'leader_user_id' => 'αρχηγός βάρδιας',
            'status' => 'κατάσταση',
        ];
    }
}
