<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\ClotureCaisse;
use App\Models\Paiement;
use App\Models\Retour;
use App\Models\Vente;
use Illuminate\Support\Facades\DB;

/**
 * Registre inaltérable des opérations de caisse.
 *
 * Inscription : chaque opération (vente, paiement, retour, annulation, clôture) est ajoutée à la suite,
 * avec une empreinte SHA-256 = hachage(empreinte précédente + n° d'ordre + type + référence + données).
 * Contrôle :
 *  1. la chaîne est recalculée de bout en bout (une ligne modifiée ou supprimée casse la chaîne) ;
 *  2. chaque vente, chaque paiement et chaque retour actuels sont comparés à ce qui a été inscrit
 *     (une modification faite directement dans la base est détectée).
 */
class Registre
{
    public const ORIGINE = '0000000000000000000000000000000000000000000000000000000000000000';

    public const TYPES = ['vente' => 'Vente', 'paiement' => 'Paiement', 'retour' => 'Retour', 'annulation' => 'Annulation', 'cloture' => 'Clôture de caisse', 'fusion' => 'Fusion de clients'];

    public static function empreinte(string $precedente, int $sequence, string $type, int $reference, string $donnees): string
    {
        return hash('sha256', implode('|', [$precedente, $sequence, $type, $reference, $donnees]));
    }

    /** Ajoute une opération au registre de sa boutique ; renvoie l'empreinte. */
    public function inscrire(int $boutiqueId, string $type, int $reference, array $donnees): string
    {
        return DB::transaction(function () use ($boutiqueId, $type, $reference, $donnees) {
            $derniere = DB::table('registre_caisse')->where('boutique_id', $boutiqueId)->orderByDesc('sequence')->lockForUpdate()->first();
            $sequence = ($derniere->sequence ?? 0) + 1;
            $precedente = $derniere->empreinte ?? self::ORIGINE;
            $texte = json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $empreinte = self::empreinte($precedente, $sequence, $type, $reference, $texte);
            DB::table('registre_caisse')->insert([
                'boutique_id' => $boutiqueId, 'sequence' => $sequence, 'type' => $type, 'reference_id' => $reference,
                'donnees' => $texte, 'empreinte_precedente' => $precedente, 'empreinte' => $empreinte, 'created_at' => now(),
            ]);

            return $empreinte;
        });
    }

    // --- Données signées de chaque opération (champs qui ne doivent plus jamais changer) ---

    public static function donneesVente(Vente $v): array
    {
        return [
            'numero' => $v->numero, 'date' => $v->date_vente->format('Y-m-d H:i:s'), 'client_id' => $v->client_id, 'user_id' => $v->user_id,
            'total_ht' => (int) $v->total_ht, 'remise' => (int) $v->remise, 'tva' => (int) $v->total_tva, 'total_ttc' => (int) $v->total_ttc,
            'lignes' => $v->lignes()->orderBy('id')->get()->map(fn ($l) => [$l->produit_id, $l->designation, (float) $l->quantite, (int) $l->prix_unitaire, (int) $l->total])->all(),
        ];
    }

    public static function donneesPaiement(Paiement $p): array
    {
        return ['vente_id' => $p->vente_id, 'montant' => (int) $p->montant, 'mode' => $p->mode,
            'date' => $p->date_paiement->format('Y-m-d H:i:s'), 'user_id' => $p->user_id];
    }

    public static function donneesRetour(Retour $r): array
    {
        return ['vente_id' => $r->vente_id, 'numero' => $r->numero, 'montant' => (int) $r->montant, 'rembourse' => (int) $r->rembourse];
    }

    public static function donneesCloture(ClotureCaisse $c): array
    {
        return ['user_id' => $c->user_id, 'jour' => $c->jour->toDateString(), 'total_ventes' => (int) $c->total_ventes,
            'especes_theoriques' => (int) $c->especes_theoriques, 'especes_comptees' => (int) $c->especes_comptees, 'ecart' => (int) $c->ecart];
    }

    /**
     * Contrôle complet d'une boutique.
     *
     * @return array{intact:bool, operations:int, anomalies:string[], anterieures:int, derniere:?string}
     */
    public function verifier(Boutique $b): array
    {
        $anomalies = [];
        $precedente = self::ORIGINE;
        $attendu = 1;
        $inscrits = ['vente' => [], 'paiement' => [], 'retour' => [], 'annulation' => []];
        $fusions = [];   // client absorbé => client conservé (fusions de fiches en double, inscrites au registre)
        $nb = 0;

        foreach (DB::table('registre_caisse')->where('boutique_id', $b->id)->orderBy('sequence')->cursor() as $l) {
            $nb++;
            if ((int) $l->sequence !== $attendu) {
                $anomalies[] = "Opérations manquantes dans le registre : n° {$attendu} à ".($l->sequence - 1).' supprimé(s).';
            }
            if ($l->empreinte_precedente !== $precedente
                || self::empreinte($l->empreinte_precedente, (int) $l->sequence, $l->type, (int) $l->reference_id, $l->donnees) !== $l->empreinte) {
                $anomalies[] = "Registre modifié à l'opération n° {$l->sequence} (".(self::TYPES[$l->type] ?? $l->type).').';
            }
            $precedente = $l->empreinte;
            $attendu = (int) $l->sequence + 1;
            if (isset($inscrits[$l->type])) {
                $inscrits[$l->type][$l->reference_id] = json_decode($l->donnees, true);
            }
            if ($l->type === 'fusion' && is_array($f = json_decode($l->donnees, true)) && isset($f['ancien'], $f['nouveau'])) {
                $fusions[(int) $f['ancien']] = (int) $f['nouveau'];
            }
        }

        // Comparaison avec les données actuelles de la base
        $premiereVente = $inscrits['vente'] ? min(array_keys($inscrits['vente'])) : null;
        $ventes = Vente::withoutGlobalScopes()->where('boutique_id', $b->id)->with('lignes')->get()->keyBy('id');
        $retoursParVente = Retour::withoutGlobalScopes()->where('boutique_id', $b->id)->get()->groupBy('vente_id');
        $anterieures = 0;
        foreach ($ventes as $id => $v) {
            $s = $inscrits['vente'][$id] ?? null;
            if (! $s) {
                $premiereVente !== null && $id > $premiereVente
                    ? $anomalies[] = "Vente {$v->numero} absente du registre (ajoutée hors du logiciel)."
                    : $anterieures++;

                continue;
            }
            $retours = $retoursParVente->get($id, collect());
            $id = (int) $id;
            // Registre trafiqué au point d'être illisible : l'anomalie de chaîne est déjà signalée
            if (! is_array($s) || ! isset($s['numero'], $s['date'], $s['total_ttc']) || ! array_key_exists('client_id', $s)) {
                $anomalies[] = "Vente {$v->numero} : données du registre illisibles.";

                continue;
            }
            // Client inscrit, suivi à travers les fusions de fiches signées (A fusionné dans B, puis B dans C…)
            $clientInscrit = $s['client_id'];
            for ($i = 0; $clientInscrit !== null && isset($fusions[(int) $clientInscrit]) && $i < 50; $i++) {
                $clientInscrit = $fusions[(int) $clientInscrit];
            }
            if ($v->numero !== $s['numero'] || $v->date_vente->format('Y-m-d H:i:s') !== $s['date'] || (string) $v->client_id !== (string) $clientInscrit) {
                $anomalies[] = "Vente {$v->numero} : numéro, date ou client modifié.";
            }
            if ((int) $v->total_ttc !== $s['total_ttc'] - (int) $retours->sum('montant')) {
                $anomalies[] = "Vente {$v->numero} : montant modifié (inscrit ".gnf($s['total_ttc']).', actuel '.gnf($v->total_ttc).', retours '.gnf($retours->sum('montant')).').';
            }
            foreach ($retours as $r) {
                if (($inscrits['retour'][$r->id]['montant'] ?? null) !== (int) $r->montant) {
                    $anomalies[] = "Retour {$r->numero} ({$v->numero}) absent du registre ou modifié.";
                }
            }
            if (($v->statut === 'annulee') !== isset($inscrits['annulation'][$id])) {
                $anomalies[] = "Vente {$v->numero} : statut d'annulation incohérent avec le registre.";
            }
        }
        $paiements = Paiement::withoutGlobalScopes()->where('boutique_id', $b->id)->get()->keyBy('id');
        foreach (array_diff_key($inscrits['paiement'], $paiements->all()) as $s) {
            $anomalies[] = 'Paiement de '.gnf((int) ($s['montant'] ?? 0)).' sur la vente '.($ventes[$s['vente_id'] ?? 0]->numero ?? '?').' supprimé de la base.';
        }
        foreach ($paiements as $p) {
            $s = $inscrits['paiement'][$p->id] ?? null;
            if (! $s) {
                if ($premiereVente !== null && (int) $p->vente_id >= $premiereVente) {
                    $anomalies[] = 'Paiement de '.gnf($p->montant).' sur la vente '.($ventes[$p->vente_id]->numero ?? '?').' absent du registre.';
                }

                continue;
            }
            if (($s['montant'] ?? null) !== (int) $p->montant || ($s['mode'] ?? null) !== $p->mode || (int) ($s['vente_id'] ?? 0) !== (int) $p->vente_id) {
                $anomalies[] = 'Paiement sur la vente '.($ventes[$p->vente_id]->numero ?? '?').' modifié (inscrit '.gnf((int) ($s['montant'] ?? 0)).', actuel '.gnf($p->montant).').';
            }
        }
        // Un paiement ou une vente supprimés de la base mais présents au registre
        foreach (array_diff_key($inscrits['vente'], $ventes->all()) as $s) {
            $anomalies[] = 'Vente '.($s['numero'] ?? '?').' supprimée de la base.';
        }

        return ['intact' => ! $anomalies, 'operations' => $nb, 'anomalies' => array_values(array_unique($anomalies)),
            'anterieures' => $anterieures, 'derniere' => $precedente === self::ORIGINE ? null : $precedente];
    }
}
