<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\JournalActivite;
use App\Models\LigneVente;
use App\Models\Paiement;
use App\Models\Retour;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Support\Facades\DB;

/**
 * Retour partiel de marchandise (avoir).
 *
 * Règles :
 * - seule une vente validée peut faire l'objet d'un retour, dans le délai fixé par la boutique
 *   (au-delà, seul l'administrateur peut l'accepter) ;
 * - on ne retourne pas plus que ce qui reste vendu sur chaque ligne ;
 * - la valeur retournée tient compte de la remise et de la TVA de la vente (au prorata) ;
 * - la vente est ramenée à son montant net ; si le client avait déjà payé plus que ce net,
 *   la différence lui est remboursée (paiement négatif, déduit de la caisse du jour) ;
 * - le client peut préférer un avoir (bon d'achat nominatif) : rien ne sort de la caisse ;
 *   ce qui avait été payé en avoir est de toute façon rendu en avoir ;
 * - les produits reviennent en stock (mouvement « Retour client ») ;
 * - un appareil suivi par numéro de série : on indique quels numéros reviennent (autant que d'articles rendus) ;
 *   ils sont marqués « repris » (la garantie ne s'applique plus, l'appareil peut être revendu).
 */
class RetourService
{
    public function __construct(private StockService $stock, private NumeroService $numeros, private CaisseService $caisse)
    {
    }

    /**
     * @param  array<int|string, float|string>  $quantites  ligne_vente_id => quantité retournée
     */
    /**
     * @param  array<int|string, array<int, int|string>>  $series  ligne_vente_id => [numero_serie_id rapportés]
     */
    public function enregistrer(Vente $vente, array $quantites, string $motif, string $modeRemboursement, User $auteur, array $series = []): Retour
    {
        $quantites = array_filter(array_map(fn ($q) => round((float) str_replace(',', '.', (string) $q), 2), $quantites), fn ($q) => $q > 0);
        if (! $quantites) {
            throw new OperationRefusee('Indiquez au moins une quantité à retourner.');
        }

        return DB::transaction(function () use ($vente, $quantites, $motif, $modeRemboursement, $auteur, $series) {
            $v = Vente::whereKey($vente->id)->lockForUpdate()->firstOrFail();
            if ($v->statut !== 'validee') {
                throw new OperationRefusee('Seule une vente validée peut faire l\'objet d\'un retour.');
            }
            // Le retour modifie la vente d'origine : impossible si son mois est clôturé
            boutique()?->verifierPeriodeOuverte($v->date_vente, 'accepter un retour sur cette vente');
            $delai = boutique()?->delai_retour_jours;
            if ($delai && $v->date_vente->copy()->addDays($delai)->endOfDay()->isPast() && ! $auteur->role?->systeme) {
                throw new OperationRefusee("Cette vente date de plus de {$delai} jours : seul l'administrateur de la boutique peut accepter le retour.");
            }

            $lignes = LigneVente::where('vente_id', $v->id)->whereIn('id', array_keys($quantites))->lockForUpdate()->get()->keyBy('id');
            if ($lignes->count() !== count($quantites)) {
                throw new OperationRefusee('Une ligne du retour n\'appartient pas à cette vente.');
            }

            $htRetourne = 0;
            foreach ($quantites as $id => $q) {
                $l = $lignes[$id];
                if ($q > $l->quantite + 0.001) {
                    throw new OperationRefusee("« {$l->designation} » : ".qte($q).' à retourner, mais seulement '.qte($l->quantite).' encore vendu(s).');
                }
                $htRetourne += (int) round($l->prix_unitaire * $q);
            }
            $seriesRendues = $this->seriesRendues($lignes, $quantites, $series);

            // Vente en prix TTC : les lignes contiennent la TVA ; la somme des lignes n'est plus total_ht
            $prixTtc = (bool) $v->prix_ttc;
            $sommeLignes = $prixTtc ? (int) LigneVente::where('vente_id', $v->id)->sum('total') : (int) $v->total_ht;
            // Part de remise et de TVA correspondant à la marchandise retournée (au prorata du sous-total)
            $part = $sommeLignes > 0 ? $htRetourne / $sommeLignes : 0;
            $remiseRetour = (int) round($v->remise * $part);
            // TVA remboursée ligne par ligne, au taux appliqué lors de la vente (multi-taux, exonérations)
            $tvaRetour = 0.0;
            foreach ($quantites as $idLigne => $qRetour) {
                $l = $lignes[$idLigne];
                $htLigne = $l->prix_unitaire * $qRetour;
                $base = $htLigne - ($sommeLignes > 0 ? $v->remise * $htLigne / $sommeLignes : 0);
                $taux = (float) ($l->taux_tva ?? $v->tva_taux);
                $tvaRetour += $prixTtc ? $base * $taux / (100 + $taux) : $base * $taux / 100;
            }
            $tvaRetour = min((int) round($tvaRetour), (int) $v->total_tva);
            // Prix TTC : le client récupère le prix payé (la TVA y est déjà comprise)
            $montant = $prixTtc ? $htRetourne - $remiseRetour : $htRetourne - $remiseRetour + $tvaRetour;

            $nouveauTotal = $v->total_ttc - $montant;
            $aRendre = max(0, $v->montant_paye - $nouveauTotal);
            // Ce qui a été payé en points de fidélité est rendu en points, pas en espèces
            $pointsPayes = (int) Paiement::where('vente_id', $v->id)->where('mode', Fidelite::MODE)->sum('montant');
            $pointsRendus = min($aRendre, max(0, $pointsPayes));
            // Ce qui a été payé avec une carte cadeau retourne sur la carte
            $cartePayee = (int) Paiement::where('vente_id', $v->id)->where('mode', CartesCadeaux::MODE)->sum('montant');
            $versCarte = min($aRendre - $pointsRendus, max(0, $cartePayee));
            // Ce qui a été payé en avoir est rendu en avoir ; le reste aussi si le client choisit l'avoir
            $avoirPaye = (int) Paiement::where('vente_id', $v->id)->where('mode', Avoirs::MODE)->sum('montant');
            $versAvoir = min($aRendre - $pointsRendus - $versCarte, max(0, $avoirPaye));
            if ($modeRemboursement === Avoirs::MODE) {
                if (! $v->client_id) {
                    throw new OperationRefusee('Un avoir est nominatif : rattachez d\'abord la vente à un client, ou remboursez autrement.');
                }
                $versAvoir = $aRendre - $pointsRendus - $versCarte;
            }
            $rembourse = $aRendre - $pointsRendus - $versCarte - $versAvoir;   // argent réellement rendu
            if ($rembourse > 0) {
                $this->caisse->verifierOuverte($auteur); // on rend de l'argent : la caisse doit être ouverte
            }

            $retour = Retour::create([
                'numero' => $this->numeros->suivant('retour', 'RET'),
                'vente_id' => $v->id, 'montant' => $montant, 'rembourse' => $rembourse + $versAvoir + $versCarte,
                'mode_remboursement' => $rembourse > 0 ? $modeRemboursement : ($versAvoir > 0 ? Avoirs::MODE : ($versCarte > 0 ? CartesCadeaux::MODE : null)),
                'motif' => $motif, 'user_id' => $auteur->id,
            ]);
            app(Registre::class)->inscrire($v->boutique_id, 'retour', $retour->id, Registre::donneesRetour($retour));

            foreach ($quantites as $id => $q) {
                $l = $lignes[$id];
                $total = (int) round($l->prix_unitaire * $q);
                $retour->lignes()->create([
                    'ligne_vente_id' => $l->id, 'produit_id' => $l->produit_id, 'designation' => $l->designation,
                    'quantite' => $q, 'facteur' => $l->facteur ?: 1, 'unite' => $l->unite, 'prix_unitaire' => $l->prix_unitaire, 'total' => $total,
                ]);
                // La ligne porte désormais les quantités nettes (marges et rapports restent justes)
                $l->update([
                    'quantite' => round($l->quantite - $q, 2),
                    'quantite_retournee' => round($l->quantite_retournee + $q, 2),
                    'total' => max(0, $l->total - $total),
                ]);
                if ($l->produit) {
                    // Un carton retourné remet ses 12 unités en stock
                    $this->stock->mouvement($l->produit, 'retour_client', round($q * ($l->facteur ?: 1), 2), $retour, "Retour {$retour->numero} ({$v->numero})");
                }
            }

            if ($pointsRendus > 0) {
                Paiement::create([
                    'vente_id' => $v->id, 'client_id' => $v->client_id, 'montant' => -$pointsRendus,
                    'mode' => Fidelite::MODE, 'reference' => "Points rendus {$retour->numero}",
                    'date_paiement' => now(), 'user_id' => $auteur->id,
                ]);
                app(Fidelite::class)->rendre($v, $pointsRendus, "Retour {$retour->numero} : points rendus");
            }
            if ($versAvoir > 0) {
                Paiement::create([
                    'vente_id' => $v->id, 'client_id' => $v->client_id, 'montant' => -$versAvoir,
                    'mode' => Avoirs::MODE, 'reference' => "Avoir {$retour->numero}",
                    'date_paiement' => now(), 'user_id' => $auteur->id,
                ]);
                app(Avoirs::class)->crediter($v->client, $versAvoir, $v, $retour);
            }
            if ($versCarte > 0) {
                Paiement::create([
                    'vente_id' => $v->id, 'client_id' => $v->client_id, 'montant' => -$versCarte,
                    'mode' => CartesCadeaux::MODE, 'reference' => "Carte recréditée {$retour->numero}",
                    'date_paiement' => now(), 'user_id' => $auteur->id,
                ]);
                app(CartesCadeaux::class)->rendreSurVente($v, $versCarte, "Retour {$retour->numero} ({$v->numero})");
            }
            if ($rembourse > 0) {
                Paiement::create([
                    'vente_id' => $v->id, 'client_id' => $v->client_id, 'montant' => -$rembourse,
                    'mode' => $modeRemboursement, 'reference' => "Remboursement {$retour->numero}",
                    'date_paiement' => now(), 'user_id' => $auteur->id,
                ]);
            }
            $v->update([
                'total_ht' => $prixTtc ? $v->total_ht - $htRetourne + $tvaRetour : $v->total_ht - $htRetourne,
                'remise' => $v->remise - $remiseRetour,
                'total_tva' => $v->total_tva - $tvaRetour,
                'total_ttc' => $nouveauTotal,
                'montant_retourne' => $v->montant_retourne + $montant,
                'montant_paye' => $v->montant_paye - $rembourse - $pointsRendus - $versAvoir - $versCarte,
            ]);

            if ($seriesRendues->isNotEmpty()) {
                \App\Models\NumeroSerie::whereIn('id', $seriesRendues)->update(['retour_id' => $retour->id]);
            }

            JournalActivite::noter('retour', "Retour {$retour->numero} sur {$v->numero} : ".gnf($montant)
                .($rembourse ? ', remboursé '.gnf($rembourse) : '').($versAvoir ? ', avoir de '.gnf($versAvoir) : '').($versCarte ? ', '.gnf($versCarte).' recrédités sur la carte cadeau' : '')
                .(! $rembourse && ! $versAvoir && ! $versCarte ? ', déduit du crédit' : '')." ({$motif})");

            return $retour;
        });
    }

    /**
     * Numéros de série rapportés : pour chaque ligne suivie dont les numéros ont été saisis, il faut désigner
     * autant de numéros que d'appareils rendus (seulement parmi ceux encore chez le client).
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function seriesRendues($lignes, array $quantites, array $series): \Illuminate\Support\Collection
    {
        $ids = collect();
        foreach ($quantites as $id => $q) {
            $l = $lignes[$id];
            $enregistres = \App\Models\NumeroSerie::where('ligne_vente_id', $l->id)->whereNull('retour_id')->pluck('id');
            $choisis = collect($series[$id] ?? [])->map(fn ($x) => (int) $x)->unique()->values();
            if ($choisis->diff($enregistres)->isNotEmpty()) {
                throw new OperationRefusee("« {$l->designation} » : un numéro de série choisi n'appartient pas à cette vente.");
            }
            if ($enregistres->isEmpty() || ! $l->produit?->suivi_serie) {
                continue;   // numéros jamais saisis : rien à désigner
            }
            $unites = (int) round($q * ($l->facteur ?: 1));
            $nonSaisis = max(0, $l->nombreSeriesAttendues() - $enregistres->count());   // appareils vendus sans numéro noté
            if ($choisis->count() > $unites || $choisis->count() < $unites - $nonSaisis) {
                throw new OperationRefusee("« {$l->designation} » : cochez le numéro de série de chacun des {$unites} appareil(s) rapporté(s).");
            }
            $ids = $ids->concat($choisis);
        }

        return $ids;
    }
}
