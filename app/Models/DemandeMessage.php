<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandeMessage extends Model
{
    protected $fillable = ['demande_id', 'user_id', 'du_proprietaire', 'contenu'];

    protected $casts = ['du_proprietaire' => 'boolean'];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
