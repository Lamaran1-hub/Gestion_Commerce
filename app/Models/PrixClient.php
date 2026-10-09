<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prix convenu avec un client pour un produit (vente à l'unité). */
class PrixClient extends Model
{
    use AppartientABoutique;

    protected $table = 'prix_clients';

    protected $fillable = ['boutique_id', 'client_id', 'produit_id', 'prix', 'user_id'];

    protected $casts = ['prix' => 'integer'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Prix négociés de plusieurs clients, pour la caisse : [client_id => [produit_id => prix]].
     *
     * @return array<int, array<int, int>>
     */
    public static function parClient(iterable $clientIds): array
    {
        $carte = [];
        foreach (self::whereIn('client_id', collect($clientIds)->all())->get(['client_id', 'produit_id', 'prix']) as $p) {
            $carte[$p->client_id][$p->produit_id] = $p->prix;
        }

        return $carte;
    }
}
