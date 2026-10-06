<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Commission d'un vendeur pour un mois, versée (montant et taux figés). */
class CommissionVersee extends Model
{
    use AppartientABoutique;

    protected $table = 'commissions_versees';

    protected $fillable = ['user_id', 'mois', 'ca_ht', 'taux', 'montant', 'mode', 'reference', 'depense_id', 'verse_par'];

    protected $casts = ['mois' => 'date', 'ca_ht' => 'integer', 'taux' => 'float', 'montant' => 'integer'];

    public function vendeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verse_par');
    }

    public function depense(): BelongsTo
    {
        return $this->belongsTo(Depense::class);
    }
}
