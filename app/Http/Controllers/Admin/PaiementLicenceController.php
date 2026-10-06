<?php

namespace App\Http\Controllers\Admin;

use App\Exports\TableauExport;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\JournalActivite;
use App\Models\PaiementLicence;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/** Paiements de licences reçus des clients : enregistrement, historique, reçus. */
class PaiementLicenceController extends Controller
{
    public function index(Request $request)
    {
        [$du, $au] = $this->periode($request);
        $requete = $this->requete($request, $du, $au);

        return view('admin.paiements.index', [
            'paiements' => (clone $requete)->with(['boutique', 'plan', 'auteur'])->latest('paye_le')->latest('id')->paginate(30)->withQueryString(),
            'total' => (int) (clone $requete)->reels()->sum('montant'),
            'nombre' => (clone $requete)->count(),
            'parMode' => (clone $requete)->selectRaw('mode, SUM(montant) as total')->groupBy('mode')->pluck('total', 'mode'),
            'boutiques' => Boutique::orderBy('nom')->get(['id', 'nom']),
            'du' => $du, 'au' => $au,
        ]);
    }

    public function store(Request $request, Boutique $boutique)
    {
        $request->merge(['montant' => montant_saisi($request->montant)]);
        $d = $request->validate([
            'plan_id' => ['nullable', 'exists:plans,id'],
            'duree' => ['required', Rule::in(['1', '3', '6', '12', '24', '36', 'illimitee'])],
            'montant' => ['required', 'integer', 'min:0'],
            'mode' => ['required', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'reference' => ['nullable', 'string', 'max:120'],
            'paye_le' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $d['mois'] = $d['duree'] === 'illimitee' ? null : (int) $d['duree'];

        // La formule choisie doit couvrir l'usage réel du client
        if ($plan = \App\Models\Plan::find($d['plan_id'] ?? $boutique->plan_id)) {
            if ($depassements = $boutique->depassementsFormule($plan)) {
                return back()->withInput()->with('erreur', "La formule {$plan->nom} est trop petite pour ce client : ".implode(' et ', $depassements)
                    .'. Choisissez une formule supérieure ou demandez-lui de désactiver des comptes.');
            }
        }
        $d['reference'] = reference_paiement() ?? ($d['reference'] ?? null);

        $paiement = $boutique->enregistrerPaiementLicence($d, $request->user());
        $boutique->refresh();
        JournalActivite::noterPour($boutique->id, 'licence', "Paiement {$paiement->numero} de ".gnf($paiement->montant).' : licence '.$paiement->libellePeriode());
        // Le client reçoit la confirmation dans le logiciel et par e-mail, reçu PDF joint
        $admins = \App\Models\User::where('boutique_id', $boutique->id)->where('actif', true)->whereHas('role', fn ($q) => $q->where('systeme', true))->get();
        $notification = new \App\Notifications\LicenceActivee($paiement);
        \Illuminate\Support\Facades\Notification::sendNow($admins, $notification, ['database']);
        $emails = \App\Support\Courrier::envoyer($admins, $notification, 'licence', $boutique->id);

        return back()->with('succes', "Paiement {$paiement->numero} de ".gnf($paiement->montant).' enregistré. Licence active '
            .($boutique->abonnement_expire_le ? "jusqu'au ".$boutique->abonnement_expire_le->format('d/m/Y') : 'sans échéance').'.'
            .($emails ? ' Confirmation envoyée par e-mail au client.' : ''))
            ->with('recu_licence', $paiement->id);
    }

    /** Reçu PDF à remettre au client. */
    public function recu(PaiementLicence $paiement)
    {
        return Pdf::loadView('pdf.recu-licence', ['p' => $paiement->load(['boutique', 'plan', 'auteur'])])
            ->setPaper('a5')
            ->stream("recu-{$paiement->numero}.pdf");
    }

    /**
     * Annule un paiement saisi par erreur. L'échéance revient à la fin du paiement précédent
     * (ou à la veille de la période annulée s'il n'y en a pas).
     */
    public function destroy(PaiementLicence $paiement)
    {
        $boutique = $paiement->boutique;
        $numero = $paiement->numero;
        $estDernier = $boutique->paiementsLicence()->reorder()->latest('id')->value('id') === $paiement->id;
        $paiement->delete();
        JournalActivite::noterPour($boutique->id, 'licence', "Paiement {$numero} de ".gnf($paiement->montant).' annulé');

        if ($estDernier) {
            $precedent = $boutique->paiementsLicence()->reorder()->latest('id')->first();
            $boutique->update($precedent
                ? ['abonnement_expire_le' => $precedent->periode_au?->toDateString()]
                : ['abonnement_expire_le' => $paiement->periode_du->copy()->subDay()->toDateString(), 'statut' => 'essai']);
        }

        return back()->with('succes', "Paiement {$numero} annulé.".($estDernier ? " L'échéance de la licence a été rétablie." : ''));
    }

    public function export(Request $request, string $format)
    {
        [$du, $au] = $this->periode($request);
        $lignes = $this->requete($request, $du, $au)->with(['boutique', 'plan'])->orderBy('paye_le')->get()->map(fn (PaiementLicence $p) => [
            'numero' => $p->numero, 'date' => $p->paye_le->format('d/m/Y'), 'client' => $p->boutique?->nom,
            'formule' => $p->plan?->nom, 'periode' => $p->libellePeriode(), 'mode' => $p->libelleMode(),
            'reference' => $p->reference, 'montant' => $p->montant,
        ])->all();
        $colonnes = ['numero' => 'N°', 'date' => 'Date', 'client' => 'Client', 'formule' => 'Formule', 'periode' => 'Période',
            'mode' => 'Mode', 'reference' => 'Référence', 'montant' => 'Montant'];
        $titre = 'Paiements de licences';
        $sousTitre = 'du '.\Carbon\Carbon::parse($du)->format('d/m/Y').' au '.\Carbon\Carbon::parse($au)->format('d/m/Y');
        $totaux = ['montant' => array_sum(array_column($lignes, 'montant'))];
        $fichier = 'paiements-licences-'.now()->format('Y-m-d');

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.tableau-plateforme', compact('titre', 'sousTitre', 'colonnes', 'lignes', 'totaux') + ['montants' => ['montant']])
                ->setPaper('a4', 'landscape')->download($fichier.'.pdf');
        }

        return Excel::download(new TableauExport($titre, $colonnes, $lignes, ['montant'], null, $sousTitre, $totaux), $fichier.'.xlsx');
    }

    private function periode(Request $request): array
    {
        return [$request->du ?: now()->startOfYear()->toDateString(), $request->au ?: now()->toDateString()];
    }

    private function requete(Request $request, string $du, string $au)
    {
        return PaiementLicence::whereDate('paye_le', '>=', $du)->whereDate('paye_le', '<=', $au)
            ->when($request->boutique_id, fn ($q) => $q->where('boutique_id', $request->boutique_id))
            ->when($request->mode, fn ($q) => $q->where('mode', $request->mode));
    }
}
