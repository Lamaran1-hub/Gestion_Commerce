<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Renseigne created_by / updated_by comme dans l'application WinDev d'origine. */
trait Horodatage
{
    public static function bootHorodatage(): void
    {
        static::creating(function ($m) {
            $m->created_by ??= auth()->id();
            $m->updated_by ??= auth()->id();
        });
        static::updating(function ($m) {
            $m->updated_by = auth()->id() ?? $m->updated_by;
        });
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
