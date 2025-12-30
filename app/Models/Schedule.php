<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Schedule extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'start_time',
        'end_time',
        'break_duration',
        'start_break',
        'end_break',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        // start_time et end_time sont stockés comme TIME dans la DB, on les garde en string
        // 'start_time' => 'datetime',
        // 'end_time' => 'datetime',
        'break_duration' => 'datetime',
    ];
    
    /**
     * Accesseur pour formater start_time en HH:mm
     */
    public function getStartTimeAttribute($value)
    {
        if (!$value) return null;
        
        // Si c'est déjà au format HH:mm, le retourner tel quel
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return substr($value, 0, 5); // Retourner juste HH:mm
        }
        
        // Si c'est un datetime ou timestamp, extraire juste l'heure
        try {
            // Essayer de parser comme Carbon
            $date = \Carbon\Carbon::parse($value);
            return $date->format('H:i');
        } catch (\Exception $e) {
            // Si ça échoue, essayer de parser comme DateTime
            try {
                $date = new \DateTime($value);
                return $date->format('H:i');
            } catch (\Exception $e2) {
                // Si tout échoue, retourner la valeur telle quelle
                \Log::warning('Impossible de formater start_time: ' . $value);
                return $value;
            }
        }
    }
    
    /**
     * Accesseur pour formater end_time en HH:mm
     */
    public function getEndTimeAttribute($value)
    {
        if (!$value) return null;
        
        // Si c'est déjà au format HH:mm, le retourner tel quel
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return substr($value, 0, 5); // Retourner juste HH:mm
        }
        
        // Si c'est un datetime ou timestamp, extraire juste l'heure
        try {
            // Essayer de parser comme Carbon
            $date = \Carbon\Carbon::parse($value);
            return $date->format('H:i');
        } catch (\Exception $e) {
            // Si ça échoue, essayer de parser comme DateTime
            try {
                $date = new \DateTime($value);
                return $date->format('H:i');
            } catch (\Exception $e2) {
                // Si tout échoue, retourner la valeur telle quelle
                \Log::warning('Impossible de formater end_time: ' . $value);
                return $value;
            }
        }
    }
    
    /**
     * Accesseur pour formater start_break en HH:mm
     */
    public function getStartBreakAttribute($value)
    {
        if (!$value) return null;
        
        // Si c'est déjà au format HH:mm, le retourner tel quel
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return substr($value, 0, 5);
        }
        
        try {
            $date = \Carbon\Carbon::parse($value);
            return $date->format('H:i');
        } catch (\Exception $e) {
            try {
                $date = new \DateTime($value);
                return $date->format('H:i');
            } catch (\Exception $e2) {
                return $value;
            }
        }
    }
    
    /**
     * Accesseur pour formater end_break en HH:mm
     */
    public function getEndBreakAttribute($value)
    {
        if (!$value) return null;
        
        // Si c'est déjà au format HH:mm, le retourner tel quel
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return substr($value, 0, 5);
        }
        
        try {
            $date = \Carbon\Carbon::parse($value);
            return $date->format('H:i');
        } catch (\Exception $e) {
            try {
                $date = new \DateTime($value);
                return $date->format('H:i');
            } catch (\Exception $e2) {
                return $value;
            }
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function timeEntry(): HasOne
    {
        return $this->hasOne(TimeEntry::class);
    }
}
