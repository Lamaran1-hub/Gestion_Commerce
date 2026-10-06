<?php

namespace App\Http\Controllers;

use App\Models\CommandeLicence;
use App\Models\Plan;
use App\Services\Paiement\LicenceEnLigneService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Achat de licence par la boutique elle-même, payé en ligne avec Djomy. */
class LicenceEnLigneController extends Controller
{
    public function __construct(private LicenceEnLigneService $service)
    {
    }

    public function commander(Request $request)
    {
        abort_unless($this->service->disponible(), 404);

        $d = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('actif', true)],
            'mois' => ['required', 'integer', Rule::in(LicenceEnLigneService::DUREES)],
            'numero_payeur' => ['required', 'string', 'max:20'],
            'moyen' => ['nullable', Rule::in(\App\Support\MoyensDjomy::actifs())],
        ], ['numero_payeur.required' => 'Indiquez le numéro de téléphone du payeur.']);

        $numero = numero_guinee($d['numero_payeur']);
        if (! $numero) {
            return back()->withInput()->withErrors(['numero_payeur' => 'Numéro invalide : saisissez 9 chiffres commençant par 6 (ex. 622 00 00 00).']);
        }

        $commande = $this->service->commander(boutique(), Plan::findOrFail($d['plan_id']), (int) $d['mois'], $numero, $request->user(), $d['moyen'] ?? null);

        // Le client paie sur la page sécurisée de Djomy, puis revient sur « retour »
        return redirect()->away($commande->url_paiement);
    }

    /** Retour depuis Djomy : on ne croit pas l'URL, on vérifie le statut chez Djomy. */
    public function retour(Request $request, CommandeLicence $commande)
    {
        $this->verifierProprietaire($commande);
        $commande = $this->service->synchroniser($commande);

        if ($request->boolean('annule') && $commande->statut === 'en_attente') {
            return redirect()->route('abonnement')->with('info', 'Paiement interrompu. Vous pouvez recommencer quand vous voulez.');
        }

        return redirect()->route('abonnement', ['commande' => $commande->reference])->with(...$this->message($commande));
    }

    /** Suivi automatique depuis la page « Ma licence » tant que le paiement est en cours. */
    public function statut(CommandeLicence $commande)
    {
        $this->verifierProprietaire($commande);
        $commande = $this->service->synchroniser($commande);

        return response()->json([
            'statut' => $commande->statut,
            'libelle' => $commande->libelleStatut(),
            'termine' => $commande->statut !== 'en_attente',
        ]);
    }

    private function message(CommandeLicence $c): array
    {
        return match ($c->statut) {
            'payee' => ['succes', 'Paiement reçu, merci ! Votre licence est activée.'],
            'a_valider' => ['succes', "Paiement reçu, merci ! L'éditeur active votre licence très rapidement."],
            'en_attente' => ['info', 'Paiement en cours de confirmation par votre opérateur. Cette page se mettra à jour automatiquement.'],
            'anomalie' => ['erreur', "Le montant reçu ne correspond pas au prix de la licence. L'éditeur a été prévenu et vous contactera."],
            default => ['erreur', "Le paiement n'a pas abouti ({$c->libelleStatut()}). Aucun montant n'a été validé ; vous pouvez réessayer."],
        };
    }

    private function verifierProprietaire(CommandeLicence $commande): void
    {
        abort_unless($commande->boutique_id === boutique()?->id, 404);
    }
}
