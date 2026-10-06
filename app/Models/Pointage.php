<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pointage extends Model
{
    use AppartientABoutique;

    protected $fillable = ['user_id', 'arrivee', 'depart', 'note', 'corrige_par'];

    protected $casts = ['arrivee' => 'datetime', 'depart' => 'datetime'];

    public function employe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Minutes travaillées (jusqu'à maintenant si le départ n'est pas encore pointé). */
    public function minutes(): int
    {
        return (int) $this->arrivee->diffInMinutes($this->depart ?? now());
    }

    /** Oubli de pointer le départ : arrivée d'un jour passé toujours ouverte. */
    public function oubli(): bool
    {
        return ! $this->depart && ! $this->arrivee->isToday();
    }

    public static function duree(int $minutes): string
    {
        return intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
