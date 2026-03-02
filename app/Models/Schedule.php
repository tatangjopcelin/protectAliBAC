<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Schedule extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
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
        // start_time, end_time, break_duration : colonnes TIME, gardés en string pour éviter
        // qu'un "00:05" soit sérialisé en datetime (ex. 2026-01-01 00:05:00) et affiché en "121560m"
    ];
    
    /**
     * Formate la date pour l'API (évite les problèmes de fuseau horaire)
     */
    public function getFormattedDateAttribute(): string
    {
        if (!$this->attributes['date']) {
            return '';
        }
        
        // Utiliser la valeur brute de la date pour éviter les problèmes de fuseau horaire
        $dateValue = $this->attributes['date'];
        
        // Si c'est déjà au format yyyy-MM-dd, le retourner
        if (is_string($dateValue) && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateValue)) {
            return substr($dateValue, 0, 10);
        }
        
        // Sinon, parser et formater
        try {
            $date = \Carbon\Carbon::parse($dateValue);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return $dateValue;
        }
    }
    
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

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
