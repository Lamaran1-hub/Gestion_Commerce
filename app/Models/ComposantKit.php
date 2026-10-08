<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un composant d'un kit : « 5 cahiers » dans le « Pack rentrée ». */
class ComposantKit extends Model
{
    use AppartientABoutique;

    protected $table = 'composants_kit';

    protected $fillable = ['boutique_id', 'kit_id', 'composant_id', 'quantite'];

    protected $casts = ['quantite' => 'float'];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'kit_id')->withTrashed();
    }

    public function composant(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'composant_id')->withTrashed();
    }
}
