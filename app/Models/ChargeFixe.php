<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une charge qui revient chaque mois (loyer, électricité, salaire…). */
class ChargeFixe extends Model
{
    use AppartientABoutique;

    protected $table = 'charges_fixes';

    protected $fillable = ['boutique_id', 'motif', 'categorie', 'montant', 'mode', 'jour', 'actif', 'user_id'];

    protected $casts = ['montant' => 'integer', 'jour' => 'integer', 'actif' => 'boolean'];

    public function paiements(): HasMany
    {
        return $this->hasMany(Depense::class, 'charge_fixe_id');
    }

    /** Date à laquelle la charge est due dans le mois donné. */
    public function echeance(Carbon $mois): Carbon
    {
        return $mois->copy()->startOfMonth()->day(min($this->jour, $mois->daysInMonth));
    }
}
