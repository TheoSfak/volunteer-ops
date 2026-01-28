<?php

namespace App\Modules\Documents\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    use HasFactory;

    /**
     * Πίνακας βάσης δεδομένων.
     */
    protected $table = 'files';

    /**
     * Πεδία που επιτρέπεται η μαζική ανάθεση.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'original_name',
        'stored_name',
        'path',
        'disk',
        'mime_type',
        'size',
        'uploaded_by',
        'fileable_type',
        'fileable_id',
    ];

    /**
     * Μετατροπές τύπων.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'size' => 'integer',
    ];

    /**
     * Polymorphic relation.
     */
    public function fileable()
    {
        return $this->morphTo();
    }

    /**
     * Σχέση με χρήστη που ανέβασε το αρχείο.
     */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Σχέση με έγγραφα.
     */
    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Μέγεθος σε human-readable format.
     */
    public function getHumanSizeAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Έλεγχος αν είναι εικόνα.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * Έλεγχος αν είναι PDF.
     */
    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }
}
