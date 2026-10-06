<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VenteEnAttente extends Model
{
    use AppartientABoutique;

    protected $table = 'ventes_en_attente';

    protected $fillable = ['user_id', 'client_id', 'libelle', 'lignes', 'total'];

    protected $casts = ['lignes' => 'array', 'total' => 'integer'];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function nbArticles(): int
    {
        return count($this->lignes ?? []);
    }
}
