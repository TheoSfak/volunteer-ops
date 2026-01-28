<?php

namespace App\Modules\Missions\Requests;

use App\Modules\Missions\Models\Mission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMissionRequest extends FormRequest
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
            'department_id' => ['sometimes', 'exists:departments,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'mission_type' => ['sometimes', Rule::in(Mission::TYPES)],
            'location_text' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after_or_equal:start_date'],
            'capacity_total' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Μηνύματα σφαλμάτων στα Ελληνικά.
     */
    public function messages(): array
    {
        return [
            'department_id.exists' => 'Το επιλεγμένο τμήμα δεν υπάρχει.',
            'title.max' => 'Ο τίτλος δεν μπορεί να υπερβαίνει τους 255 χαρακτήρες.',
            'description.max' => 'Η περιγραφή δεν μπορεί να υπερβαίνει τους 5000 χαρακτήρες.',
            'mission_type.in' => 'Ο τύπος αποστολής πρέπει να είναι VOLUNTEER ή MEDICAL.',
            'location_text.max' => 'Η τοποθεσία δεν μπορεί να υπερβαίνει τους 500 χαρακτήρες.',
            'lat.between' => 'Το γεωγραφικό πλάτος πρέπει να είναι μεταξύ -90 και 90.',
            'lng.between' => 'Το γεωγραφικό μήκος πρέπει να είναι μεταξύ -180 και 180.',
            'end_date.after_or_equal' => 'Η ημερομηνία λήξης πρέπει να είναι μετά ή ίση με την έναρξη.',
            'capacity_total.min' => 'Η χωρητικότητα πρέπει να είναι τουλάχιστον 1.',
        ];
    }

    /**
     * Ονόματα πεδίων στα Ελληνικά.
     */
    public function attributes(): array
    {
        return [
            'department_id' => 'τμήμα',
            'title' => 'τίτλος',
            'description' => 'περιγραφή',
            'mission_type' => 'τύπος αποστολής',
            'location_text' => 'τοποθεσία',
            'lat' => 'γεωγραφικό πλάτος',
            'lng' => 'γεωγραφικό μήκος',
            'start_date' => 'ημερομηνία έναρξης',
            'end_date' => 'ημερομηνία λήξης',
            'capacity_total' => 'συνολική χωρητικότητα',
        ];
    }
}
