<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\JournalActivite;
use App\Models\Paiement;
use App\Models\Retour;
use App\Models\Vente;

/**
 * Échange d'articles : le client rapporte un article et en prend un autre.
 *
 * Le retour convertit ce qui lui revient en bon d'échange (nominatif ou non : il est attaché au retour, pas au client).
 * Le bon paie la nouvelle vente ; s'il vaut plus que les nouveaux articles, le reste est rendu en espèces au même moment.
 * Rien ne sort ni n'entre deux fois dans la caisse : seule la différence est réellement payée ou rendue.
 */
class Echanges
{
    public const MODE = 'echange';

    /** Bon d'échange encore utilisable (retour de la boutique courante, non consommé). */
    public function bon(?int $retourId, bool $verrouiller = false): ?Retour
    {
        if (! $retourId) {
            return null;
        }

        return Retour::whereKey($retourId)->where('mode_remboursement', self::MODE)->where('echange_restant', '>', 0)
            ->when($verrouiller, fn ($q) => $q->lockForUpdate())->first();
    }

    /**
     * Paie la vente avec le bon. Ce qui dépasse le prix des nouveaux articles est rendu en espèces au client
     * (sortie de caisse inscrite sur la vente d'origine).
     *
     * @return int le reste rendu en espèces
     */
    public function utiliser(Retour $bon, Vente $vente, int $montant): int
    {
        $bon = Retour::whereKey($bon->id)->lockForUpdate()->first();
        if (! $bon || $bon->mode_remboursement !== self::MODE || $montant > $bon->echange_restant) {
            throw new OperationRefusee('Ce bon d\'échange a déjà été utilisé.');
        }
        $reste = $bon->echange_restant - $montant;
        if ($reste > 0) {
            $origine = Vente::find($bon->vente_id);
            // Le bon (dette envers le client) diminue du reste, l'argent sort de la caisse : la vente d'origine reste équilibrée
            foreach ([[$reste, self::MODE, "Reste du bon {$bon->numero} rendu"], [-$reste, 'especes', "Reste du bon {$bon->numero} (échange {$vente->numero})"]] as [$m, $mode, $ref]) {
                Paiement::create(['vente_id' => $origine->id, 'client_id' => $origine->client_id, 'montant' => $m, 'mode' => $mode,
                    'reference' => $ref, 'date_paiement' => now(), 'user_id' => auth()->id()]);
            }
        }
        $bon->update(['echange_restant' => 0, 'echange_vente_id' => $vente->id]);
        JournalActivite::noter('retour', "Bon d'échange {$bon->numero} utilisé sur {$vente->numero} : ".gnf($montant)
            .($reste > 0 ? ', reste '.gnf($reste).' rendu en espèces' : ''));

        return $reste;
    }

    /** Vente payée par un bon annulée : le bon retrouve la valeur utilisée (les articles reviennent en stock). */
    public function annulerVente(Vente $vente): void
    {
        $utilise = (int) Paiement::where('vente_id', $vente->id)->where('mode', self::MODE)->sum('montant');
        $bon = Retour::where('echange_vente_id', $vente->id)->lockForUpdate()->first();
        if ($bon && $utilise > 0) {
            $bon->update(['echange_restant' => $utilise, 'echange_vente_id' => null]);
        }
    }

    /** Un bon d'échange a été émis sur cette vente : l'annuler rembourserait deux fois. */
    public function emisSur(Vente $vente): bool
    {
        return Retour::where('vente_id', $vente->id)->where('mode_remboursement', self::MODE)->exists();
    }
}
