<?php

namespace App\Modules\Missions\Requests;

use App\Modules\Missions\Models\Mission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMissionRequest extends FormRequest
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
            'department_id' => ['required', 'exists:departments,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(Mission::TYPES)],
            'location' => ['nullable', 'string', 'max:500'],
            'location_details' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after_or_equal:start_datetime'],
            'requirements' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'is_urgent' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Μηνύματα σφαλμάτων στα Ελληνικά.
     */
    public function messages(): array
    {
        return [
            'department_id.required' => 'Το τμήμα είναι υποχρεωτικό.',
            'department_id.exists' => 'Το επιλεγμένο τμήμα δεν υπάρχει.',
            'title.required' => 'Ο τίτλος είναι υποχρεωτικός.',
            'title.max' => 'Ο τίτλος δεν μπορεί να υπερβαίνει τους 255 χαρακτήρες.',
            'description.max' => 'Η περιγραφή δεν μπορεί να υπερβαίνει τους 5000 χαρακτήρες.',
            'type.required' => 'Ο τύπος αποστολής είναι υποχρεωτικός.',
            'type.in' => 'Ο τύπος αποστολής πρέπει να είναι VOLUNTEER ή MEDICAL.',
            'location.max' => 'Η τοποθεσία δεν μπορεί να υπερβαίνει τους 500 χαρακτήρες.',
            'latitude.between' => 'Το γεωγραφικό πλάτος πρέπει να είναι μεταξύ -90 και 90.',
            'longitude.between' => 'Το γεωγραφικό μήκος πρέπει να είναι μεταξύ -180 και 180.',
            'start_datetime.required' => 'Η ημερομηνία έναρξης είναι υποχρεωτική.',
            'end_datetime.required' => 'Η ημερομηνία λήξης είναι υποχρεωτική.',
            'end_datetime.after_or_equal' => 'Η ημερομηνία λήξης πρέπει να είναι μετά ή ίση με την έναρξη.',
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
            'type' => 'τύπος αποστολής',
            'location' => 'τοποθεσία',
            'location_details' => 'λεπτομέρειες τοποθεσίας',
            'latitude' => 'γεωγραφικό πλάτος',
            'longitude' => 'γεωγραφικό μήκος',
            'start_datetime' => 'ημερομηνία έναρξης',
            'end_datetime' => 'ημερομηνία λήξης',
            'is_urgent' => 'επείγουσα',
        ];
    }
}
