<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Client;
use App\Models\Devis;
use App\Models\JournalActivite;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Support\Facades\DB;

/**
 * Devis / facture proforma.
 *
 * Règles :
 * - mêmes prix qu'une vente (détail, gros, remise plafonnée, pas de vente à perte) ;
 * - aucun mouvement de stock tant que le client n'a pas acheté ;
 * - les prix sont garantis jusqu'à la date de validité (15 jours par défaut, réglable) ;
 * - un devis expiré ne se transforme pas : on le recrée au prix du jour ;
 * - la transformation en vente vérifie le stock et la caisse comme une vente normale.
 */
class DevisService
{
    public function __construct(private VenteService $ventes, private NumeroService $numeros)
    {
    }

    public function creer(array $donnees, bool $peutModifierPrix, ?User $auteur): Devis
    {
        $boutique = boutique();

        return DB::transaction(function () use ($donnees, $peutModifierPrix, $auteur, $boutique) {
            $lignes = collect($donnees['lignes'] ?? [])->filter(fn ($l) => ($l['quantite'] ?? 0) > 0);
            if ($lignes->isEmpty()) {
                throw new OperationRefusee('Ajoutez au moins un produit au devis.');
            }
            $client = ! empty($donnees['client_id']) ? (Client::find($donnees['client_id']) ?? throw new OperationRefusee('Client introuvable.')) : null;
            [$details, $totalHt] = $this->ventes->detaillerLignes($lignes, $client, $peutModifierPrix);
            $remise = min((int) ($donnees['remise'] ?? 0), $totalHt);
            $prixTtc = VenteService::prixTtc($boutique);
            $this->ventes->verifierPrixEtRemise($boutique, $details, $totalHt, $remise, $prixTtc);
            $totaux = \App\Support\Tva::totaux($details, $totalHt, $remise, $prixTtc);
            $taux = $totaux['principal'];
            $tva = $totaux['tva'];

            $devis = Devis::create([
                'numero' => $this->numeros->suivant('devis', 'DV'),
                'client_id' => $client?->id, 'client_nom' => $client ? null : ($donnees['client_nom'] ?? null),
                'date_devis' => now()->toDateString(),
                'valable_jusqu_au' => now()->addDays($boutique->validite_devis_jours ?: 15)->toDateString(),
                'total_ht' => $totaux['total_ht'], 'remise' => $remise, 'tva_taux' => $taux, 'total_tva' => $tva,
                'total_ttc' => $totaux['total_ttc'], 'prix_ttc' => $prixTtc, 'note' => $donnees['note'] ?? null, 'user_id' => $auteur?->id,
                'origine' => $donnees['origine'] ?? 'caisse', 'client_telephone' => $donnees['client_telephone'] ?? null,
            ]);
            foreach ($details as $d) {
                $devis->lignes()->create(['produit_id' => $d['produit']->id, 'designation' => $d['designation'],
                    'quantite' => $d['quantite'], 'facteur' => $d['facteur'], 'unite' => $d['unite'],
                    'prix_unitaire' => $d['prix'], 'total' => $d['total'], 'taux_tva' => $d['taux']]);
            }
            JournalActivite::noter('devis', "Devis {$devis->numero} de ".gnf($devis->total_ttc).' pour '.$devis->nomClient());

            return $devis;
        });
    }

    /** Le client revient acheter : la vente reprend les prix garantis du devis. */
    public function convertir(Devis $devis, array $paiement, User $auteur): Vente
    {
        return DB::transaction(function () use ($devis, $paiement, $auteur) {
            $d = Devis::whereKey($devis->id)->lockForUpdate()->firstOrFail();
            if ($d->statut !== 'en_cours') {
                throw new OperationRefusee("Ce devis est déjà {$d->libelleEtat()} : il ne peut plus être transformé.");
            }
            if ($d->estExpire()) {
                throw new OperationRefusee('Ce devis a expiré le '.$d->valable_jusqu_au->format('d/m/Y')
                    .' : ses prix ne sont plus garantis. Recréez-le au prix du jour.');
            }

            $vente = $this->ventes->creer([
                'client_id' => $d->client_id,
                'lignes' => $d->lignes->filter(fn ($l) => $l->produit_id)->map(fn ($l) => [
                    'produit_id' => $l->produit_id, 'quantite' => $l->quantite, 'prix_unitaire' => $l->prix_unitaire,
                    'conditionnement' => $l->facteur > 1,
                ])->values()->all(),
                'remise' => $d->remise,
                'acompte' => $d->acompte,
                'reference_acompte' => "Acompte {$d->numero}",
                'montant_recu' => $paiement['montant_recu'] ?? null,
                'mode' => $paiement['mode'] ?? 'especes',
                'reference' => $paiement['reference'] ?? null,
                'note' => "Devis {$d->numero}",
            ], true);

            $d->update(['statut' => 'converti', 'vente_id' => $vente->id]);
            JournalActivite::noter('devis', "Devis {$d->numero} transformé en vente {$vente->numero}");

            return $vente;
        });
    }

    /** @return int acompte remboursé au client */
    public function annuler(Devis $devis, string $modeRemboursement = 'especes', ?User $auteur = null): int
    {
        return DB::transaction(function () use ($devis, $modeRemboursement, $auteur) {
            $d = Devis::whereKey($devis->id)->lockForUpdate()->firstOrFail();
            if ($d->statut !== 'en_cours') {
                throw new OperationRefusee('Seul un devis en cours peut être annulé.');
            }
            // L'avance du client lui est rendue
            $rendu = app(Acomptes::class)->rembourser($d, $modeRemboursement, $auteur ?? auth()->user());
            $d->update(['statut' => 'annule']);
            JournalActivite::noter('devis', "Devis {$d->numero} annulé".($rendu ? ' — acompte de '.gnf($rendu).' remboursé' : ''));

            return $rendu;
        });
    }

    /** Nouveau devis identique, aux prix et à la validité du jour. */
    public function renouveler(Devis $devis, User $auteur): Devis
    {
        return DB::transaction(fn () => $this->recreer($devis, $auteur));
    }

    private function recreer(Devis $devis, User $auteur): Devis
    {
        $nouveau = $this->creer([
            'client_id' => $devis->client_id, 'client_nom' => $devis->client_nom, 'note' => $devis->note,
            'lignes' => $devis->lignes->filter(fn ($l) => $l->produit_id)
                ->map(fn ($l) => ['produit_id' => $l->produit_id, 'quantite' => $l->quantite, 'conditionnement' => $l->facteur > 1])->all(),
        ], false, $auteur);
        if ($devis->statut === 'en_cours') {
            app(Acomptes::class)->transferer($devis, $nouveau);   // l'avance du client suit sa commande
            $devis->update(['statut' => 'annule']);
        }

        return $nouveau;
    }
}
