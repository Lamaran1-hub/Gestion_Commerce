<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Depense extends Model
{
    use AppartientABoutique;

    protected $fillable = ['motif', 'categorie', 'montant', 'mode', 'date_depense', 'note', 'user_id'];

    protected $casts = ['date_depense' => 'date', 'montant' => 'integer'];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
