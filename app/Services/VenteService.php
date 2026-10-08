<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Client;
use App\Models\JournalActivite;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\Vente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VenteService
{
    public function __construct(private StockService $stock, private NumeroService $numeros)
    {
    }

    /**
     * Enregistre une vente complète en une seule transaction :
     * numéro, lignes, sortie de stock et premier paiement. Si une étape échoue, rien n'est gardé.
     *
     * @param  array{client_id?:int|null, lignes:array<int,array{produit_id:int,quantite:float,prix_unitaire?:int}>, remise?:int, montant_recu?:int, mode?:string, reference?:string|null, note?:string|null}  $donnees
     */
    public function creer(array $donnees, bool $peutModifierPrix = false): Vente
    {
        $boutique = boutique();
        // Vente faite hors connexion : enregistrée à sa date réelle (voir synchroniserHorsLigne)
        $horsLigne = $donnees['hors_ligne'] ?? null;
        $date = $horsLigne['date'] ?? now();
        if (! $horsLigne || $date->isToday()) {
            app(CaisseService::class)->verifierOuverte(auth()->user());
        }
        if ($horsLigne) {
            $boutique->verifierPeriodeOuverte($date, 'enregistrer une vente à cette date');
        }

        return DB::transaction(function () use ($donnees, $boutique, $peutModifierPrix, $horsLigne, $date) {
            $lignes = collect($donnees['lignes'] ?? [])->filter(fn ($l) => ($l['quantite'] ?? 0) > 0);
            if ($lignes->isEmpty()) {
                throw new OperationRefusee('Ajoutez au moins un produit à la vente.');
            }

            $clientId = $donnees['client_id'] ?? null;
            $client = $clientId ? (Client::find($clientId) ?? throw new OperationRefusee('Client introuvable.')) : null;

            [$details, $totalHt] = $this->detaillerLignes($lignes, $client, $peutModifierPrix);

            $remise = min((int) ($donnees['remise'] ?? 0), $totalHt);
            // Prix TTC (TVA comprise dans les prix affichés) ou hors taxe (la TVA s'ajoute) : réglage de la boutique, mémorisé sur la vente
            $prixTtc = self::prixTtc($boutique);
            $this->verifierPrixEtRemise($boutique, $details, $totalHt, $remise, $prixTtc);
            // TVA multi-taux : remise répartie au prorata, TVA calculée par taux (produits exonérés compris)
            $totaux = \App\Support\Tva::totaux($details, $totalHt, $remise, $prixTtc);
            $taux = $totaux['principal'];
            $tva = $totaux['tva'];
            $ttc = $totaux['total_ttc'];
            // Acompte déjà versé sur la commande (devis) : il règle la vente en premier
            $acompte = max(0, (int) ($donnees['acompte'] ?? 0));
            if ($acompte > $ttc) {
                throw new OperationRefusee("L'acompte versé (".gnf($acompte).') dépasse le total de la vente ('.gnf($ttc).") : remboursez d'abord la différence au client.");
            }
            // Points de fidélité utilisés ensuite ; le montant reçu couvre le reste
            $points = ! empty($donnees['utiliser_points']) ? app(Fidelite::class)->utilisables($client, $ttc - $acompte) : 0;
            // Avoir du client (retour précédent) : utilisé ensuite, jamais au-delà de son solde
            $avoir = ! empty($donnees['utiliser_avoir']) ? app(Avoirs::class)->utilisable($client, $ttc - $acompte - $points) : 0;
            // Carte cadeau présentée par le client : son code fait foi, jamais au-delà du solde ni après expiration
            $carte = null;
            $parCarte = 0;
            if (trim((string) ($donnees['carte_cadeau'] ?? '')) !== '') {
                if ($horsLigne) {
                    throw new OperationRefusee('Les cartes cadeaux ne sont pas utilisables hors connexion.');
                }
                $cartes = app(CartesCadeaux::class);
                $carte = $cartes->trouver($donnees['carte_cadeau']);
                if ($refus = $cartes->refus($carte)) {
                    throw new OperationRefusee($refus);
                }
                $parCarte = $cartes->utilisable($carte, $ttc - $acompte - $points - $avoir);
            }
            // Échange : le bon issu du retour paie les nouveaux articles (le reste éventuel est rendu en espèces)
            $bon = null;
            $parBon = 0;
            if (! empty($donnees['echange_id'])) {
                if ($horsLigne) {
                    throw new OperationRefusee('Un échange ne se fait pas hors connexion.');
                }
                $bon = app(Echanges::class)->bon((int) $donnees['echange_id'], verrouiller: true)
                    ?? throw new OperationRefusee('Ce bon d\'échange a déjà été utilisé ou n\'existe pas.');
                $parBon = min($bon->echange_restant, max(0, $ttc - $acompte - $points - $avoir - $parCarte));
            }
            // Paiement mixte : autres moyens d'abord (Orange Money, carte…), puis le moyen principal pour le reste.
            // Un paiement électronique ne rend pas de monnaie : les autres moyens ne peuvent pas dépasser le montant dû.
            $autres = collect($donnees['paiements_autres'] ?? [])
                ->map(fn ($p) => ['mode' => $p['mode'] ?? 'especes', 'montant' => (int) ($p['montant'] ?? 0), 'reference' => $p['reference'] ?? null])
                ->filter(fn ($p) => $p['montant'] > 0)->values();
            $totalAutres = (int) $autres->sum('montant');
            $avantAutres = $ttc - $acompte - $points - $avoir - $parCarte - $parBon;
            if ($totalAutres > $avantAutres) {
                throw new OperationRefusee('Les paiements saisis ('.gnf($totalAutres).') dépassent le montant dû ('.gnf($avantAutres).').');
            }
            $recu = max(0, (int) ($donnees['montant_recu'] ?? $avantAutres - $totalAutres));
            $paye = min($recu, $avantAutres - $totalAutres);
            $regle = $acompte + $points + $avoir + $parCarte + $parBon + $totalAutres + $paye;
            if ($horsLigne && $regle < $ttc) {
                throw new OperationRefusee('Vente hors connexion non payée entièrement ('.gnf($regle).' sur '.gnf($ttc).') : le crédit n\'est pas possible sans connexion.');
            }

            if ($regle < $ttc && ! $clientId) {
                throw new OperationRefusee('Une vente à crédit doit être rattachée à un client : sélectionnez ou créez le client.');
            }
            if ($client && $regle < $ttc) {
                $this->verifierCredit($boutique, $client, $ttc - $regle);
            }
            // Vente à crédit : date à laquelle le client promet de payer (délai de la boutique à défaut)
            $echeance = $regle < $ttc ? $this->echeance($donnees['echeance'] ?? null, $date, $boutique) : null;

            $vente = Vente::create([
                'numero' => $this->numeros->suivant('vente', 'V'),
                'uuid_hors_ligne' => $horsLigne['uuid'] ?? null,
                'synchronisee_le' => $horsLigne ? now() : null,
                'client_id' => $clientId,
                'date_vente' => $date,
                'total_ht' => $totaux['total_ht'],
                'remise' => $remise,
                'tva_taux' => $taux,
                'total_tva' => $tva,
                'total_ttc' => $ttc,
                'prix_ttc' => $prixTtc,
                'montant_paye' => 0,
                'echeance' => $echeance,
                'note' => $donnees['note'] ?? null,
                'user_id' => auth()->id(),
            ]);

            foreach ($details as $d) {
                $vente->lignes()->create([
                    'produit_id' => $d['produit']->id,
                    'designation' => $d['designation'],
                    'quantite' => $d['quantite'],
                    'facteur' => $d['facteur'],
                    'unite' => $d['unite'],
                    'prix_unitaire' => $d['prix'],
                    // Coût exprimé dans l'unité vendue : la marge (prix − coût) × quantité reste juste
                    'prix_achat' => (int) round($d['produit']->prix_achat * $d['facteur']),
                    'total' => $d['total'],
                    'taux_tva' => $d['taux'],
                    'garantie_mois' => $d['produit']->garantie_mois,   // figée : changer la fiche produit ne modifie pas les garanties déjà données
                ]);
                // Le stock est tenu en unités de base : 2 cartons de 12 = 24 unités
                $this->stock->mouvement($d['produit'], 'vente', -round($d['quantite'] * $d['facteur'], 2), $vente,
                    'Vente '.$vente->numero.($horsLigne ? ' (hors connexion)' : ''), forcer: (bool) $horsLigne);
            }

            // Registre inaltérable : la vente telle qu'enregistrée (lignes, montants) est signée avant tout paiement
            $vente->forceFill(['empreinte' => app(Registre::class)->inscrire($vente->boutique_id, 'vente', $vente->id, Registre::donneesVente($vente))])->saveQuietly();

            if ($acompte > 0) {
                $this->enregistrerPaiement($vente, $acompte, Acomptes::MODE, $donnees['reference_acompte'] ?? null, $date);
            }
            if ($points > 0) {
                app(Fidelite::class)->utiliser($client, $vente, $points);
                $this->enregistrerPaiement($vente, $points, Fidelite::MODE, null, $date);
            }
            if ($avoir > 0) {
                app(Avoirs::class)->utiliser($client, $vente, $avoir);
                $this->enregistrerPaiement($vente, $avoir, Avoirs::MODE, null, $date);
            }
            if ($parCarte > 0) {
                app(CartesCadeaux::class)->utiliser($carte, $vente, $parCarte);
                $this->enregistrerPaiement($vente, $parCarte, CartesCadeaux::MODE, 'Carte '.$carte->codeMasque(), $date);
            }
            if ($bon) {
                if ($parBon > 0) {
                    $this->enregistrerPaiement($vente, $parBon, Echanges::MODE, "Bon d'échange {$bon->numero}", $date);
                }
                app(Echanges::class)->utiliser($bon, $vente, $parBon);
            }
            foreach ($autres as $p) {
                $this->enregistrerPaiement($vente, $p['montant'], $p['mode'], $p['reference'], $date);
            }
            if ($paye > 0) {
                $this->enregistrerPaiement($vente, $paye, $donnees['mode'] ?? 'especes', $donnees['reference'] ?? null, $date);
            }

            JournalActivite::noter('vente', "Vente {$vente->numero} de ".gnf($ttc));

            return $vente->fresh(['lignes', 'client', 'paiements']);
        });
    }

    /** Encaisse un paiement sur une vente (règlement comptant ou remboursement de crédit). */
    public function encaisser(Vente $vente, int $montant, string $mode, ?string $reference = null): Paiement
    {
        app(CaisseService::class)->verifierOuverte(auth()->user());

        return DB::transaction(function () use ($vente, $montant, $mode, $reference) {
            $v = Vente::whereKey($vente->id)->lockForUpdate()->firstOrFail();
            if ($v->statut !== 'validee') {
                throw new OperationRefusee('Cette vente est annulée : aucun paiement possible.');
            }
            if ($montant <= 0) {
                throw new OperationRefusee('Le montant doit être supérieur à zéro.');
            }
            if ($montant > $v->resteAPayer()) {
                throw new OperationRefusee('Le montant dépasse le reste à payer ('.gnf($v->resteAPayer()).').');
            }

            $paiement = $this->enregistrerPaiement($v, $montant, $mode, $reference);
            JournalActivite::noter('paiement', 'Paiement de '.gnf($montant)." sur {$v->numero}");

            return $paiement;
        });
    }

    /**
     * Répartit un versement d'un client sur ses crédits, du plus ancien au plus récent.
     *
     * @return int montant réellement affecté
     */
    public function encaisserClient(Client $client, int $montant, string $mode, ?string $reference = null): int
    {
        app(CaisseService::class)->verifierOuverte(auth()->user());

        return DB::transaction(function () use ($client, $montant, $mode, $reference) {
            $du = $client->soldeDu();
            if ($montant <= 0 || $montant > $du) {
                throw new OperationRefusee('Le montant doit être compris entre 1 et '.gnf($du).'.');
            }

            $reste = $montant;
            $ventes = $client->ventes()->avecReste()->orderBy('date_vente')->lockForUpdate()->get();
            foreach ($ventes as $v) {
                if ($reste <= 0) {
                    break;
                }
                $part = min($reste, $v->resteAPayer());
                $this->enregistrerPaiement($v, $part, $mode, $reference);
                $reste -= $part;
            }
            JournalActivite::noter('paiement', 'Versement de '.gnf($montant).' du client '.$client->nomComplet());

            return $montant - $reste;
        });
    }

    public function annuler(Vente $vente, string $motif): Vente
    {
        return DB::transaction(function () use ($vente, $motif) {
            $v = Vente::whereKey($vente->id)->lockForUpdate()->firstOrFail();
            if ($v->statut === 'annulee') {
                throw new OperationRefusee('Cette vente est déjà annulée.');
            }
            if ($v->livraison === 'livree') {
                throw new OperationRefusee('Cette vente a été livrée le '.$v->livree_le->format('d/m/Y')." : la marchandise est chez le client. Faites un retour de marchandise plutôt qu'une annulation.");
            }
            // Des articles ont déjà été repris en avoir : l'annulation rembourserait deux fois
            if (app(Avoirs::class)->avoirEmisSur($v) || app(Echanges::class)->emisSur($v)) {
                throw new OperationRefusee('Des articles de cette vente ont déjà été repris en avoir ou en bon d\'échange : faites un retour des articles restants plutôt qu\'une annulation.');
            }
            boutique()?->verifierPeriodeOuverte($v->date_vente, 'annuler cette vente');
            // Une annulation rend l'argent au client : impossible depuis une caisse déjà clôturée
            if ($v->montant_paye > 0) {
                app(CaisseService::class)->verifierOuverte(auth()->user());
            }
            // Passé le délai fixé par la boutique, seul l'administrateur peut encore annuler
            $delai = boutique()?->delai_annulation_heures;
            if ($delai && $v->date_vente->copy()->addHours($delai)->isPast() && ! auth()->user()?->role?->systeme) {
                throw new OperationRefusee("Cette vente date de plus de {$delai} h : seul l'administrateur de la boutique peut l'annuler.");
            }

            foreach ($v->lignes as $ligne) {
                if ($ligne->produit) {
                    $this->stock->mouvement($ligne->produit, 'annulation_vente', round($ligne->quantite * ($ligne->facteur ?: 1), 2), $v, 'Annulation '.$v->numero);
                }
            }

            $v->update([
                'statut' => 'annulee',
                'annulee_le' => now(),
                'annulee_par' => auth()->id(),
                'motif_annulation' => $motif,
            ]);
            app(Fidelite::class)->annulerVente($v);
            app(Avoirs::class)->annulerVente($v);
            app(Acomptes::class)->annulerVente($v);
            app(CartesCadeaux::class)->annulerVente($v);
            app(Echanges::class)->annulerVente($v);
            app(Registre::class)->inscrire($v->boutique_id, 'annulation', $v->id,
                ['vente_id' => $v->id, 'numero' => $v->numero, 'motif' => $motif, 'date' => now()->format('Y-m-d H:i:s'), 'user_id' => auth()->id()]);
            JournalActivite::noter('annulation', "Annulation de la vente {$v->numero} : {$motif}");

            return $v;
        });
    }

    /**
     * Détaille les lignes d'une vente ou d'un devis : produit, unité vendue (unité ou conditionnement),
     * facteur vers l'unité de base, prix applicable et total.
     *
     * @return array{0: array<int, array{produit:Produit, designation:string, quantite:float, facteur:float, unite:string, prix:int, total:int}>, 1: int}
     */
    public function detaillerLignes(Collection $lignes, ?Client $client, bool $peutModifierPrix): array
    {
        $produits = Produit::whereIn('id', $lignes->pluck('produit_id'))->get()->keyBy('id');
        $promotions = app(Promotions::class);
        $totalHt = 0;
        $details = [];
        foreach ($lignes as $l) {
            $produit = $produits->get($l['produit_id']) ?? throw new OperationRefusee('Un produit est introuvable.');
            $quantite = round((float) $l['quantite'], 2);
            $parConditionnement = ! empty($l['conditionnement']) && $produit->aConditionnement();
            $facteur = $parConditionnement ? (float) $produit->qte_conditionnement : 1.0;
            // Prix : conditionnement, ou détail / gros (quantité atteinte ou client grossiste) ; libre seulement avec le droit de remise
            $prixSaisi = $peutModifierPrix && isset($l['prix_unitaire']);
            $prix = $prixSaisi ? (int) $l['prix_unitaire']
                : ($parConditionnement ? $produit->prixConditionnement() : $produit->prixPour($quantite, $client));
            // Promotion en cours : le client paie le plus bas du prix promo et du prix normal (ou de gros)
            $promo = $prixSaisi ? null : $promotions->prix($produit, $parConditionnement);
            $enPromo = $promo !== null && $promo < $prix;
            if ($enPromo) {
                $prix = $promo;
            }
            $total = (int) round($prix * $quantite);
            $totalHt += $total;
            $details[] = [
                'produit' => $produit, 'quantite' => $quantite, 'facteur' => $facteur, 'prix' => $prix, 'total' => $total,
                'taux' => \App\Support\Tva::tauxProduit($produit),
                'unite' => $parConditionnement ? $produit->conditionnement : $produit->unite,
                'designation' => $produit->designation.($parConditionnement ? ' ('.$produit->libelleConditionnement().')' : '').($enPromo ? ' — promo' : ''),
            ];
        }

        return [$details, $totalHt];
    }

    /**
     * Règles de prix : remise plafonnée (en % du sous-total) et pas de vente sous le prix d'achat,
     * sauf si la boutique l'autorise dans ses paramètres.
     *
     * @param  array<int,array{produit:Produit,prix:int,quantite:float,facteur?:float,total:int}>  $details
     */
    public function verifierPrixEtRemise(\App\Models\Boutique $boutique, array $details, int $totalHt, int $remise, bool $prixTtc = false): void
    {
        // Prix TTC : on compare au prix d'achat le prix hors taxe (la TVA n'est pas un gain pour la boutique)
        if ($prixTtc) {
            $ht = fn (array $d, $montant) => \App\Support\Tva::horsTaxe($montant, $d['taux'] ?? 0, true);
            $details = array_map(fn ($d) => ['prix' => (int) round($ht($d, $d['prix'])), 'total' => (int) round($ht($d, $d['total'])), 'prix_affiche' => $d['prix']] + $d, $details);
            $sommeHt = (int) collect($details)->sum('total');
            $remise = $totalHt > 0 ? (int) round($remise * $sommeHt / $totalHt) : 0;
            $totalHt = $sommeHt;
        }
        $max = $boutique->remise_max_pct;
        if ($remise > 0 && $max !== null && $remise > $totalHt * $max / 100) {
            throw new OperationRefusee('La remise dépasse le maximum autorisé par la boutique : '.rtrim(rtrim(number_format($max, 2, ',', ''), '0'), ',')
                .' % du montant, soit '.gnf((int) floor($totalHt * $max / 100)).' au plus.');
        }
        if ($boutique->vente_a_perte) {
            return;
        }
        foreach ($details as $d) {
            // Coût dans l'unité vendue (un carton de 12 coûte 12 fois le prix d'achat unitaire)
            $coutUnite = (int) round($d['produit']->prix_achat * ($d['facteur'] ?? 1));
            if ($coutUnite > 0 && $d['prix'] < $coutUnite) {
                throw new OperationRefusee('« '.($d['designation'] ?? $d['produit']->designation).' » serait vendu '.gnf($d['prix'])
                    .(isset($d['prix_affiche']) ? ' hors taxe (prix affiché '.gnf($d['prix_affiche']).' TVA comprise)' : '')
                    .', sous son prix d\'achat ('.gnf($coutUnite).'). La vente à perte est désactivée.');
            }
        }
        $cout = (int) round(collect($details)->sum(fn ($d) => $d['produit']->prix_achat * ($d['facteur'] ?? 1) * $d['quantite']));
        if ($remise > 0 && $totalHt - $remise < $cout) {
            throw new OperationRefusee('Avec cette remise, la vente ('.gnf($totalHt - $remise).') passe sous le prix d\'achat des produits ('
                .gnf($cout).'). Remise possible au plus : '.gnf($totalHt - $cout).'.');
        }
    }

    /** Les prix de la boutique sont-ils TVA comprise ? (seulement si elle facture la TVA) */
    public static function prixTtc(?\App\Models\Boutique $boutique = null): bool
    {
        $boutique ??= boutique();

        return (bool) ($boutique?->tva_active && $boutique?->prix_ttc);
    }

    /** Règles du crédit client : plafond de dette et délai maximum de remboursement. */
    private function verifierCredit(\App\Models\Boutique $boutique, Client $client, int $nouveauCredit): void
    {
        $plafond = $client->plafond_credit ?? $boutique->plafond_credit_defaut;
        if ($plafond !== null) {
            $du = $client->soldeDu();
            if ($du + $nouveauCredit > $plafond) {
                throw new OperationRefusee("Plafond de crédit dépassé pour {$client->nomComplet()} : il doit déjà ".gnf($du)
                    .', plafond '.gnf($plafond).'. Crédit encore possible : '.gnf(max(0, $plafond - $du)).'.');
            }
        }
        // Règle activée par le délai de crédit des paramètres : pas de nouveau crédit tant qu'une échéance est dépassée
        if ($boutique->delai_credit_jours) {
            $ancienne = $client->ventes()->enRetard()->orderBy('echeance')->first();
            if ($ancienne) {
                throw new OperationRefusee("{$client->nomComplet()} a un crédit en retard (vente {$ancienne->numero}, à payer avant le "
                    .$ancienne->echeance->format('d/m/Y').'). Encaissez-le ou reportez son échéance avant d\'accorder un nouveau crédit.');
            }
        }
    }

    /** Échéance d'un crédit : saisie à la caisse (aujourd'hui au plus tôt, un an au plus tard) ou délai de la boutique. */
    private function echeance(?string $saisie, \DateTimeInterface $date, \App\Models\Boutique $boutique): \Illuminate\Support\Carbon
    {
        if (! $saisie) {
            return Vente::echeanceParDefaut($date, $boutique);
        }
        try {
            $echeance = \Illuminate\Support\Carbon::parse($saisie)->startOfDay();
        } catch (\Throwable) {
            throw new OperationRefusee('Date d\'échéance du crédit invalide.');
        }
        $jour = \Illuminate\Support\Carbon::instance($date)->startOfDay();
        if ($echeance->lt($jour) || $echeance->gt($jour->copy()->addYear())) {
            throw new OperationRefusee('L\'échéance du crédit doit être comprise entre le '.$jour->format('d/m/Y').' et le '.$jour->copy()->addYear()->format('d/m/Y').'.');
        }

        return $echeance;
    }

    /** Reporter (ou avancer) la date de paiement promise d'un crédit. */
    public function reporterEcheance(Vente $vente, string $saisie, ?string $motif = null): Vente
    {
        if ($vente->resteAPayer() <= 0) {
            throw new OperationRefusee('Cette vente est entièrement payée : il n\'y a pas d\'échéance à modifier.');
        }
        $ancienne = $vente->echeance;
        $vente->update(['echeance' => $this->echeance($saisie, now(), boutique())]);
        JournalActivite::noter('credit', "Échéance de {$vente->numero} : ".($ancienne?->format('d/m/Y') ?? '—').' → '.$vente->echeance->format('d/m/Y')
            .($motif ? " ({$motif})" : ''));

        return $vente;
    }

    private function enregistrerPaiement(Vente $vente, int $montant, string $mode, ?string $reference, ?\DateTimeInterface $date = null): Paiement
    {
        $paiement = Paiement::create([
            'vente_id' => $vente->id,
            'client_id' => $vente->client_id,
            'montant' => $montant,
            'mode' => array_key_exists($mode, config('gestion.modes_paiement')) || in_array($mode, [Fidelite::MODE, Avoirs::MODE, Acomptes::MODE, CartesCadeaux::MODE, Echanges::MODE], true) ? $mode : 'especes',
            'reference' => $reference,
            'date_paiement' => $date ?? now(),
            'user_id' => auth()->id(),
        ]);
        $vente->increment('montant_paye', $montant);

        return $paiement;
    }
}
