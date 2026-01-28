<?php

namespace App\Modules\Directory\Models;

use App\Models\User;
use App\Modules\Missions\Models\Mission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Πίνακας βάσης δεδομένων.
     */
    protected $table = 'departments';

    /**
     * Πεδία που επιτρέπεται η μαζική ανάθεση.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'parent_id',
        'is_active',
    ];

    /**
     * Μετατροπές τύπων.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Σχέση με γονικό τμήμα.
     */
    public function parent()
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    /**
     * Σχέση με θυγατρικά τμήματα.
     */
    public function children()
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    /**
     * Σχέση με χρήστες του τμήματος.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Σχέση με αποστολές του τμήματος.
     */
    public function missions()
    {
        return $this->hasMany(Mission::class);
    }

    /**
     * Scope για ενεργά τμήματα.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
