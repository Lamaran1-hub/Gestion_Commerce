<?php

namespace App\Models\Concerns;

use App\Models\Boutique;
use App\Support\BoutiqueCourante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolation des données entre boutiques : chaque requête est automatiquement
 * limitée à la boutique courante, et chaque création y est rattachée.
 */
trait AppartientABoutique
{
    public static function bootAppartientABoutique(): void
    {
        static::addGlobalScope('boutique', function (Builder $query) {
            $id = app(BoutiqueCourante::class)->id();
            if ($id !== null) {
                $query->where($query->getModel()->getTable().'.boutique_id', $id);
            }
        });

        static::creating(function ($model) {
            if (empty($model->boutique_id)) {
                $model->boutique_id = app(BoutiqueCourante::class)->id();
            }
            if (empty($model->boutique_id)) {
                throw new \LogicException('Impossible de créer '.class_basename($model).' sans boutique.');
            }
        });
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }
}
