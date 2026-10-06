<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\JournalActivite;
use App\Models\PaiementFournisseur;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Dettes fournisseurs.
 *
 * Règles :
 * - une réception peut être payée comptant, en partie ou à crédit ; à crédit, le fournisseur est obligatoire
 *   et une échéance est fixée (30 jours par défaut) ;
 * - on ne paie jamais plus que ce qui est dû ;
 * - un règlement global est imputé sur les réceptions les plus anciennes d'abord ;
 * - un règlement en espèces sort du tiroir-caisse : la caisse de l'utilisateur doit être ouverte ;
 * - l'avoir accordé par le fournisseur (après un retour) peut servir à régler, dans la limite de ce qui est disponible.
 */
class DetteFournisseurService
{
    public const ECHEANCE_PAR_DEFAUT_JOURS = 30;

    public function __construct(private CaisseService $caisse)
    {
    }

    /** Paiement enregistré au moment de la réception. */
    public function payerReception(Approvisionnement $appro, int $montant, string $mode, ?string $reference, ?User $auteur): void
    {
        if ($montant <= 0) {
            return;
        }
        if ($montant > $appro->resteAPayer()) {
            throw new OperationRefusee('Le montant payé ('.gnf($montant).') dépasse le total de la réception ('.gnf($appro->total).').');
        }
        if ($mode === 'especes') {
            $this->caisse->verifierOuverte($auteur);
        }
        $this->creerPaiement($appro, $montant, $mode, $reference, $auteur);
    }

    /** Règlement d'un fournisseur, imputé sur ses réceptions les plus anciennes. @return int montant imputé */
    public function regler(Fournisseur $fournisseur, int $montant, string $mode, ?string $reference, User $auteur): int
    {
        $du = $fournisseur->soldeDu();
        if ($montant <= 0 || $montant > $du) {
            throw new OperationRefusee('Le montant doit être compris entre 1 et '.gnf($du).' (dette envers '.$fournisseur->nom.').');
        }
        if ($mode === PaiementFournisseur::MODE_AVOIR && $montant > $fournisseur->avoirDisponible()) {
            throw new OperationRefusee('Avoir disponible chez '.$fournisseur->nom.' : '.gnf($fournisseur->avoirDisponible()).'. Réglez le reste par un autre moyen.');
        }
        if ($mode === 'especes') {
            $this->caisse->verifierOuverte($auteur);
        }

        return DB::transaction(function () use ($fournisseur, $montant, $mode, $reference, $auteur) {
            $reste = $montant;
            $receptions = Approvisionnement::where('fournisseur_id', $fournisseur->id)->avecReste()
                ->orderBy('date_appro')->orderBy('id')->lockForUpdate()->get();
            foreach ($receptions as $a) {
                if ($reste <= 0) {
                    break;
                }
                $part = min($reste, $a->resteAPayer());
                $this->creerPaiement($a, $part, $mode, $reference, $auteur);
                $reste -= $part;
            }
            JournalActivite::noter('fournisseur', "Règlement de ".gnf($montant)." à {$fournisseur->nom} (".libelle_mode($mode).')');

            return $montant - $reste;
        });
    }

    private function creerPaiement(Approvisionnement $appro, int $montant, string $mode, ?string $reference, ?User $auteur): void
    {
        PaiementFournisseur::create([
            'fournisseur_id' => $appro->fournisseur_id, 'approvisionnement_id' => $appro->id, 'montant' => $montant,
            'mode' => $mode, 'reference' => $reference, 'date_paiement' => now(), 'user_id' => $auteur?->id,
        ]);
        $appro->increment('montant_paye', $montant);
    }
}
