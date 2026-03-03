<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

class PushToken extends Model
{
    use Notifiable;

    protected $fillable = ['user_id', 'token', 'platform'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Route pour le canal APNs (utilisé par laravel-notification-channels/apn).
     */
    public function routeNotificationForApn(): string
    {
        return $this->token;
    }
}
