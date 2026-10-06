<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mouvement d'une carte cadeau : émission (+), utilisation (−), recrédit (+, vente annulée ou retour), remboursement (−). */
class MouvementCarteCadeau extends Model
{
    use AppartientABoutique;

    public $timestamps = false;

    protected $table = 'mouvements_carte_cadeau';

    protected $fillable = ['carte_cadeau_id', 'vente_id', 'type', 'montant', 'mode', 'reference', 'motif', 'user_id', 'date_mouvement'];

    protected $casts = ['montant' => 'integer', 'date_mouvement' => 'datetime'];

    public function carte(): BelongsTo
    {
        return $this->belongsTo(CarteCadeau::class, 'carte_cadeau_id');
    }

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelle(): string
    {
        return match ($this->type) {
            'emission' => 'Carte vendue',
            'utilisation' => 'Dépensée',
            'recredit' => 'Recréditée',
            'remboursement' => 'Solde remboursé',
            default => $this->type,
        };
    }
}
