<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Client;
use App\Models\JournalActivite;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fiches clients en double (client recréé à la caisse au lieu d'être retrouvé) : fusion dans une seule fiche.
 *
 * - Ventes, paiements, devis, tickets en attente, points, avoirs et cartes cadeaux passent sur la fiche conservée ;
 *   soldes de points et d'avoir additionnés ; coordonnées manquantes complétées ; la fiche en double est supprimée.
 * - Les ventes sont signées avec leur client dans le registre anti-fraude : la fusion y est inscrite elle aussi,
 *   pour que la vérification reconnaisse ce changement de client (un changement fait hors du logiciel reste détecté).
 */
class FusionClients
{
    /** Tables qui désignent un client. */
    private const TABLES = ['ventes', 'paiements', 'devis', 'points_fidelite', 'ventes_en_attente', 'avoirs', 'cartes_cadeaux'];

    private const COORDONNEES = ['prenom', 'telephone', 'email', 'adresse', 'quartier', 'commune', 'ville', 'date_naissance', 'plafond_credit'];

    public function __construct(private Registre $registre)
    {
    }

    public function fusionner(Client $garde, Client $doublon, User $auteur): Client
    {
        if ($garde->id === $doublon->id) {
            throw new OperationRefusee('Choisissez deux fiches différentes.');
        }
        if ($garde->boutique_id !== $doublon->boutique_id) {
            throw new OperationRefusee('Ces deux clients ne sont pas dans la même boutique.');
        }

        return DB::transaction(function () use ($garde, $doublon, $auteur) {
            $g = Client::whereKey($garde->id)->lockForUpdate()->firstOrFail();
            $d = Client::whereKey($doublon->id)->lockForUpdate()->firstOrFail();
            $ventes = Vente::withoutGlobalScopes()->where('boutique_id', $d->boutique_id)->where('client_id', $d->id)->orderBy('id')->pluck('id')->all();

            foreach (self::TABLES as $table) {
                DB::table($table)->where('boutique_id', $d->boutique_id)->where('client_id', $d->id)->update(['client_id' => $g->id]);
            }

            // Soldes additionnés, coordonnées complétées (la fiche conservée garde les siennes)
            $complements = [];
            foreach (self::COORDONNEES as $champ) {
                if (blank($g->{$champ}) && filled($d->{$champ})) {
                    $complements[$champ] = $d->{$champ};
                }
            }
            $notes = trim(collect([$g->notes, $d->notes ? 'Fiche fusionnée '.$d->code.' : '.$d->notes : null])->filter()->implode("\n"));
            // Le numéro de la fiche en double est libéré avant de la supprimer (un numéro = un client)
            $d->forceFill(['telephone' => null])->saveQuietly();
            $g->forceFill($complements + [
                'points' => (int) $g->points + (int) $d->points,
                'avoir' => (int) $g->avoir + (int) $d->avoir,
                'grossiste' => $g->grossiste || $d->grossiste,
                'notes' => $notes !== '' ? mb_substr($notes, 0, 1000) : null,
            ])->save();
            $d->forceFill(['points' => 0, 'avoir' => 0])->saveQuietly();
            $d->delete();

            // Inscription au registre : la vérification saura que ces ventes ont changé de client légitimement
            $this->registre->inscrire($g->boutique_id, 'fusion', $g->id, [
                'ancien' => $d->id, 'nouveau' => $g->id, 'ventes' => $ventes,
                'date' => now()->format('Y-m-d H:i:s'), 'user_id' => $auteur->id,
            ]);
            JournalActivite::noter('client', "Fusion de la fiche {$d->code} ({$d->nomComplet()}) dans {$g->code} ({$g->nomComplet()}) : "
                .count($ventes).' vente(s) reprise(s)');

            return $g->fresh();
        });
    }

    /**
     * Groupes de fiches probablement en double : même nom complet, ou même numéro de téléphone.
     *
     * @return Collection<int, Collection<int, Client>>
     */
    public function doublonsProbables(): Collection
    {
        $clients = Client::orderBy('id')->get(['id', 'code', 'nom', 'prenom', 'telephone', 'points', 'avoir', 'created_at']);
        $parNom = $clients->groupBy(fn ($c) => 'nom:'.self::normaliser(trim($c->prenom.' '.$c->nom)));
        $parTelephone = $clients->filter(fn ($c) => strlen(preg_replace('/\D/', '', (string) $c->telephone)) >= 8)
            ->groupBy(fn ($c) => 'tel:'.substr(preg_replace('/\D/', '', (string) $c->telephone), -9));
        $groupes = $parNom->concat($parTelephone)->filter(fn ($g) => $g->count() > 1)->values();

        // Un même ensemble de fiches ne doit apparaître qu'une fois
        return $groupes->unique(fn ($g) => $g->pluck('id')->sort()->implode(','))->values();
    }

    public static function normaliser(string $texte): string
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/', ' ', $texte)));

        return strtr($t, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
    }
}
