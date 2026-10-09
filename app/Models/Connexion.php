<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une tentative de connexion (réussie ou non) sur un compte. */
class Connexion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'email', 'ip', 'appareil', 'reussie', 'nouvel_appareil', 'jeton_appareil'];

    protected $casts = ['reussie' => 'boolean', 'nouvel_appareil' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
