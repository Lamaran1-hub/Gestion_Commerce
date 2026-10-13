<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Paiement extends Model
{
    use AppartientABoutique;

    protected $fillable = ['vente_id', 'client_id', 'montant', 'mode', 'devise', 'montant_devise', 'taux_devise', 'contre_valeur', 'reference', 'date_paiement', 'user_id'];

    protected $casts = ['date_paiement' => 'datetime', 'montant' => 'integer', 'montant_devise' => 'float', 'taux_devise' => 'float', 'contre_valeur' => 'integer', 'pointe_le' => 'datetime'];

    protected static function booted(): void
    {
        static::created(function (Paiement $p) {
            // Registre inaltérable : tout encaissement ou remboursement est inscrit, d'où qu'il vienne
            app(\App\Services\Registre::class)->inscrire($p->boutique_id, 'paiement', $p->id, \App\Services\Registre::donneesPaiement($p));
            app(\App\Services\Fidelite::class)->surPaiement($p);
        });
    }

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** Responsable qui a retrouvé (ou non) le paiement sur ses SMS ou son relevé. */
    public function pointeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pointe_par');
    }

    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleMode(): string
    {
        return libelle_mode($this->mode);
    }

    /** « Reçu 100 $ (1 $ = 8 600 GNF) · rendu 15 000 GNF » pour des espèces en devises, sinon null. */
    public function detailDevise(): ?string
    {
        if (! $this->devise) {
            return null;
        }
        $rendu = (int) $this->contre_valeur - (int) $this->montant;

        return 'Reçu '.devise($this->montant_devise, $this->devise).' (1 '.config('gestion.devises.'.$this->devise.'.1', $this->devise).' = '.gnf($this->taux_devise).')'
            .($rendu > 0 ? ' · rendu '.gnf($rendu) : '');
    }
}
