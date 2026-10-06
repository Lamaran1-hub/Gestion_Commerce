<?php

namespace App\Models;

use App\Support\BoutiqueCourante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalActivite extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'journal_activites';

    protected $fillable = ['boutique_id', 'user_id', 'action', 'description', 'ip'];

    public static function noter(string $action, string $description): void
    {
        static::create([
            'boutique_id' => app(BoutiqueCourante::class)->id() ?? auth()->user()?->boutique_id,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => mb_substr($description, 0, 250),
            'ip' => request()?->ip(),
        ]);
    }

    /** Trace une action du propriétaire sur une boutique précise (paiement, suspension…). */
    public static function noterPour(int $boutiqueId, string $action, string $description): void
    {
        static::create([
            'boutique_id' => $boutiqueId,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => mb_substr($description, 0, 250),
            'ip' => request()?->ip(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
