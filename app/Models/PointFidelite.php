<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointFidelite extends Model
{
    use AppartientABoutique;

    public const UPDATED_AT = null;

    protected $table = 'points_fidelite';

    protected $fillable = ['client_id', 'vente_id', 'points', 'motif', 'user_id'];

    protected $casts = ['points' => 'integer'];

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }
}
