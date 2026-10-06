<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Paiement extends Model
{
    use AppartientABoutique;

    protected $fillable = ['vente_id', 'client_id', 'montant', 'mode', 'reference', 'date_paiement', 'user_id'];

    protected $casts = ['date_paiement' => 'datetime', 'montant' => 'integer'];

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

    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleMode(): string
    {
        return libelle_mode($this->mode);
    }
}
