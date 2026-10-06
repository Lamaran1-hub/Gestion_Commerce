<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Horaire prévu d'un employé pour un jour. */
class Planning extends Model
{
    use AppartientABoutique;

    protected $fillable = ['user_id', 'jour', 'debut', 'fin'];

    protected $casts = ['jour' => 'date'];

    public function employe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Minutes prévues. */
    public function minutes(): int
    {
        [$h1, $m1] = array_map('intval', explode(':', $this->debut));
        [$h2, $m2] = array_map('intval', explode(':', $this->fin));

        return max(0, ($h2 * 60 + $m2) - ($h1 * 60 + $m1));
    }
}
