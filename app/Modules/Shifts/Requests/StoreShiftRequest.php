<?php

namespace App\Modules\Shifts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'leader_id' => ['nullable', 'exists:users,id'],
            'location' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'required_skills' => ['nullable', 'string'],
        ];
    }

    /**
     * Μηνύματα σφαλμάτων στα Ελληνικά.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Ο τίτλος βάρδιας είναι υποχρεωτικός.',
            'title.max' => 'Ο τίτλος βάρδιας δεν μπορεί να υπερβαίνει τους 255 χαρακτήρες.',
            'start_time.required' => 'Η ημερομηνία/ώρα έναρξης είναι υποχρεωτική.',
            'start_time.date' => 'Η ημερομηνία/ώρα έναρξης δεν είναι έγκυρη.',
            'end_time.required' => 'Η ημερομηνία/ώρα λήξης είναι υποχρεωτική.',
            'end_time.date' => 'Η ημερομηνία/ώρα λήξης δεν είναι έγκυρη.',
            'end_time.after' => 'Η λήξη πρέπει να είναι μετά την έναρξη.',
            'max_capacity.required' => 'Η χωρητικότητα είναι υποχρεωτική.',
            'max_capacity.min' => 'Η χωρητικότητα πρέπει να είναι τουλάχιστον 1.',
            'max_capacity.max' => 'Η χωρητικότητα δεν μπορεί να υπερβαίνει τα 1000.',
            'leader_id.exists' => 'Ο επιλεγμένος αρχηγός δεν υπάρχει.',
        ];
    }

    /**
     * Ονόματα πεδίων στα Ελληνικά.
     */
    public function attributes(): array
    {
        return [
            'title' => 'τίτλος βάρδιας',
            'start_time' => 'έναρξη',
            'end_dt' => 'λήξη',
            'capacity' => 'χωρητικότητα',
            'leader_user_id' => 'αρχηγός βάρδιας',
        ];
    }
}
