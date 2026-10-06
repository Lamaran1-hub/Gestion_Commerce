<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Boutique;
use App\Models\ClotureCaisse;
use App\Models\Paiement;
use App\Models\Retour;
use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Archives fiscales mensuelles (principe de conservation : 6 ans minimum).
 *
 * Chaque mois terminé donne un fichier JSON complet et lisible (ventes et lignes, paiements, retours,
 * clôtures, extrait du registre inaltérable), horodaté, dont l'empreinte SHA-256 est enregistrée :
 * on peut à tout moment prouver que le fichier conservé est identique à celui produit.
 * Une archive ne se régénère pas et ne se supprime pas depuis le logiciel.
 */
class ArchivesFiscales
{
    public const DUREE_CONSERVATION_ANS = 6;

    public function generer(Boutique $b, Carbon $mois, ?User $auteur = null): object
    {
        $du = $mois->copy()->startOfMonth();
        $au = $mois->copy()->endOfMonth();
        if ($au->isFuture()) {
            throw new OperationRefusee('Seul un mois terminé peut être archivé.');
        }
        if (DB::table('archives_fiscales')->where('boutique_id', $b->id)->whereDate('periode_du', $du->toDateString())->exists()) {
            throw new OperationRefusee('Le mois de '.$du->translatedFormat('F Y').' est déjà archivé.');
        }

        $filtre = fn ($modele) => $modele::withoutGlobalScopes()->where('boutique_id', $b->id);
        $ventes = $filtre(Vente::class)->with('lignes')->whereBetween('date_vente', [$du, $au])->orderBy('id')->get();
        $contenu = [
            'logiciel' => config('app.name'),
            'genere_le' => now()->toIso8601String(),
            'boutique' => ['id' => $b->id, 'nom' => $b->nom, 'rccm' => $b->rccm, 'nif' => $b->nif, 'adresse' => $b->adresse, 'ville' => $b->ville],
            'periode' => ['du' => $du->toDateString(), 'au' => $au->toDateString()],
            'totaux' => [
                'ventes_validees' => $ventes->where('statut', 'validee')->count(),
                'ventes_annulees' => $ventes->where('statut', 'annulee')->count(),
                'total_ttc' => (int) $ventes->where('statut', 'validee')->sum('total_ttc'),
                'total_tva' => (int) $ventes->where('statut', 'validee')->sum('total_tva'),
            ],
            'ventes' => $ventes->map(fn (Vente $v) => [
                'id' => $v->id, 'numero' => $v->numero, 'date' => $v->date_vente->toDateTimeString(), 'statut' => $v->statut,
                'client_id' => $v->client_id, 'vendeur_id' => $v->user_id, 'total_ht' => (int) $v->total_ht, 'remise' => (int) $v->remise,
                'tva' => (int) $v->total_tva, 'total_ttc' => (int) $v->total_ttc, 'paye' => (int) $v->montant_paye,
                'retourne' => (int) $v->montant_retourne, 'empreinte' => $v->empreinte, 'motif_annulation' => $v->motif_annulation,
                'lignes' => $v->lignes->map(fn ($l) => ['produit_id' => $l->produit_id, 'designation' => $l->designation, 'quantite' => (float) $l->quantite,
                    'prix_unitaire' => (int) $l->prix_unitaire, 'total' => (int) $l->total])->all(),
            ])->all(),
            'paiements' => $filtre(Paiement::class)->whereBetween('date_paiement', [$du, $au])->orderBy('id')->get()
                ->map(fn ($p) => ['id' => $p->id, 'vente_id' => $p->vente_id, 'date' => $p->date_paiement->toDateTimeString(), 'mode' => $p->mode,
                    'montant' => (int) $p->montant, 'reference' => $p->reference])->all(),
            'retours' => $filtre(Retour::class)->whereBetween('created_at', [$du, $au])->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'numero' => $r->numero, 'vente_id' => $r->vente_id, 'date' => $r->created_at->toDateTimeString(),
                    'montant' => (int) $r->montant, 'rembourse' => (int) $r->rembourse, 'motif' => $r->motif])->all(),
            'clotures' => $filtre(ClotureCaisse::class)->whereBetween('jour', [$du->toDateString(), $au->toDateString()])->orderBy('id')->get()
                ->map(fn ($c) => ['id' => $c->id, 'jour' => $c->jour->toDateString(), 'caissier_id' => $c->user_id, 'especes_theoriques' => (int) $c->especes_theoriques,
                    'especes_comptees' => (int) $c->especes_comptees, 'ecart' => (int) $c->ecart, 'empreinte' => $c->empreinte])->all(),
            'registre' => DB::table('registre_caisse')->where('boutique_id', $b->id)->whereBetween('created_at', [$du, $au])->orderBy('sequence')
                ->get(['sequence', 'type', 'reference_id', 'empreinte'])->map(fn ($r) => (array) $r)->all(),
        ];
        $texte = json_encode($contenu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $fichier = "archives/{$b->id}/archive-{$du->format('Y-m')}.json";
        Storage::put($fichier, $texte);

        $id = DB::table('archives_fiscales')->insertGetId([
            'boutique_id' => $b->id, 'periode_du' => $du->toDateString(), 'periode_au' => $au->toDateString(), 'fichier' => $fichier,
            'empreinte' => hash('sha256', $texte),
            'empreinte_registre' => DB::table('registre_caisse')->where('boutique_id', $b->id)->orderByDesc('sequence')->value('empreinte'),
            'nb_ventes' => $contenu['totaux']['ventes_validees'], 'total_ttc' => $contenu['totaux']['total_ttc'],
            'user_id' => $auteur?->id, 'created_at' => now(),
        ]);
        \App\Models\JournalActivite::noterPour($b->id, 'archive', 'Archive fiscale de '.$du->translatedFormat('F Y').' générée (empreinte '.substr(hash('sha256', $texte), 0, 16).'…)');

        return DB::table('archives_fiscales')->find($id);
    }

    /** Le fichier conservé est-il identique à celui produit ? */
    public function verifier(object $archive): bool
    {
        return Storage::exists($archive->fichier) && hash('sha256', Storage::get($archive->fichier)) === $archive->empreinte;
    }

    /** Archive automatiquement le mois précédent de chaque boutique qui a eu de l'activité (tâche du 1er du mois). */
    public function archiverMoisPrecedent(): int
    {
        $mois = now()->subMonthNoOverflow()->startOfMonth();
        $n = 0;
        foreach (Boutique::all() as $b) {
            $actif = Vente::withoutGlobalScopes()->where('boutique_id', $b->id)->whereBetween('date_vente', [$mois, $mois->copy()->endOfMonth()])->exists();
            $deja = DB::table('archives_fiscales')->where('boutique_id', $b->id)->whereDate('periode_du', $mois->toDateString())->exists();
            if ($actif && ! $deja) {
                $this->generer($b, $mois);
                $n++;
            }
        }

        return $n;
    }
}
