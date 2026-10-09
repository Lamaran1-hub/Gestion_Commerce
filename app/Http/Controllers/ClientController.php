<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\JournalActivite;
use App\Models\Vente;
use App\Services\ReleveClient;
use App\Support\Tableau;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;


class ClientController extends Controller
{
    /** Délai minimal entre deux invitations « revenez nous voir » au même client (pas de harcèlement). */
    public const DELAI_ENTRE_INVITATIONS = 14;

    public const SEGMENTS = [
        'a_relancer' => 'À relancer',
        'fideles' => 'Fidèles',
        'nouveaux' => 'Nouveaux',
        'debiteurs' => 'Doivent de l\'argent',
        'anniversaires' => 'Anniversaires (7 jours)',
    ];

    /** Clients dont l'anniversaire tombe dans les 7 prochains jours (aujourd'hui compris). */
    public static function idsAnniversaires(int $jours = 7): array
    {
        return Client::whereNotNull('date_naissance')->get(['id', 'date_naissance'])
            ->filter(fn (Client $c) => $c->joursAvantAnniversaire() !== null && $c->joursAvantAnniversaire() < $jours)
            ->pluck('id')->all();
    }

    public function index(Request $request)
    {
        $segment = array_key_exists($request->segment, self::SEGMENTS) ? $request->segment : null;
        $jours = in_array((int) $request->jours, [30, 60, 90, 180], true) ? (int) $request->jours : 30;
        $tri = in_array($request->tri, ['nom', 'dernier_achat', 'total'], true) ? $request->tri : ($segment === 'a_relancer' ? 'dernier_achat' : 'nom');

        $clients = $this->segment(Client::recherche($request->q), $segment, $jours)
            ->withCount(['ventes' => fn ($q) => $q->validees()])
            ->withSum(['ventes as total_achats' => fn ($q) => $q->validees()], 'total_ttc')
            ->withMax(['ventes as dernier_achat' => fn ($q) => $q->validees()], 'date_vente')
            ->addSelect(['total_du' => Vente::selectRaw('COALESCE(SUM(total_ttc - montant_paye), 0)')
                ->whereColumn('ventes.client_id', 'clients.id')->where('statut', 'validee')])
            ->when($tri === 'nom', fn ($q) => $q->orderBy('nom'))
            ->when($tri === 'total', fn ($q) => $q->orderByDesc('total_achats'))
            // Relance : les plus anciens d'abord ; sinon les plus récents d'abord
            ->when($tri === 'dernier_achat', fn ($q) => $segment === 'a_relancer' ? $q->orderBy('dernier_achat') : $q->orderByDesc('dernier_achat'))
            ->paginate(25)->withQueryString();

        // Anniversaires : dans l'ordre du calendrier (ceux du jour d'abord)
        if ($segment === 'anniversaires') {
            $clients->setCollection($clients->getCollection()->sortBy(fn (Client $c) => $c->joursAvantAnniversaire())->values());
        }

        $comptes = collect(self::SEGMENTS)->map(fn ($l, $cle) => $this->segment(Client::query(), $cle, $jours)->count());

        return view('clients.index', compact('clients', 'segment', 'jours', 'tri', 'comptes'));
    }

    /** Segments de clientèle, calculés sur les ventes validées. */
    private function segment($q, ?string $segment, int $jours)
    {
        $validees = fn ($v) => $v->where('statut', 'validee');

        return match ($segment) {
            // Ont déjà acheté, plus rien depuis N jours, joignables, pas invités récemment
            'a_relancer' => $q->whereHas('ventes', $validees)
                ->whereDoesntHave('ventes', fn ($v) => $validees($v)->where('date_vente', '>=', now()->subDays($jours)->startOfDay()))
                ->whereNotNull('telephone')->where('telephone', '!=', '')
                ->where(fn ($w) => $w->whereNull('derniere_invitation_le')->orWhere('derniere_invitation_le', '<', now()->subDays(self::DELAI_ENTRE_INVITATIONS))),
            // Au moins 3 achats sur les 90 derniers jours
            'fideles' => $q->whereHas('ventes', fn ($v) => $validees($v)->where('date_vente', '>=', now()->subDays(90)->startOfDay()), '>=', 3),
            'nouveaux' => $q->where('created_at', '>=', now()->subDays(30)->startOfDay()),
            'debiteurs' => $q->whereHas('ventes', fn ($v) => $validees($v)->whereColumn('total_ttc', '>', 'montant_paye')),
            'anniversaires' => $q->whereIn('id', self::idsAnniversaires()),
            default => $q,
        };
    }

    /** Vœux d'anniversaire par WhatsApp (un par an), avec le cadeau choisi par le commerçant s'il y en a un. */
    public function souhaiter(Client $client)
    {
        if ($client->voeuEnvoyeCetteAnnee()) {
            return back()->with('erreur', "Les vœux de {$client->nomComplet()} ont déjà été envoyés le {$client->dernier_voeu_le->format('d/m/Y')}.");
        }
        $b = boutique();
        $message = 'Joyeux anniversaire '.($client->prenom ?: $client->nomComplet())." ! 🎂\n\n"
            ."Toute l'équipe de {$b->nom} vous souhaite une très belle journée et une excellente année."
            .($b->cadeau_anniversaire ? "\n\n🎁 Pour l'occasion : {$b->cadeau_anniversaire}" : '')
            ."\n\nÀ très bientôt,\n{$b->nom}".($b->telephone ? " — {$b->telephone}" : '');
        $lien = lien_whatsapp($client->telephone, $message);
        if (! $lien) {
            return back()->with('erreur', "{$client->nomComplet()} n'a pas de numéro de téléphone valide.");
        }
        $client->forceFill(['dernier_voeu_le' => now()->toDateString()])->save();
        JournalActivite::noter('client', "Vœux d'anniversaire envoyés à {$client->nomComplet()}");

        return redirect()->away($lien);
    }

    /** « Revenez nous voir » : message WhatsApp personnalisé (promotions en cours, vitrine), invitation datée. */
    public function inviter(Client $client, \App\Services\Promotions $promotions)
    {
        if ($client->derniere_invitation_le?->gt(now()->subDays(self::DELAI_ENTRE_INVITATIONS))) {
            return back()->with('erreur', "{$client->nomComplet()} a déjà été invité le {$client->derniere_invitation_le->format('d/m/Y')} : attendez un peu avant de le relancer.");
        }
        $lien = lien_whatsapp($client->telephone, $this->messageInvitation($client, $promotions));
        if (! $lien) {
            return back()->with('erreur', "{$client->nomComplet()} n'a pas de numéro de téléphone valide.");
        }
        $client->forceFill(['derniere_invitation_le' => now()])->save();
        JournalActivite::noter('client', "Invitation WhatsApp envoyée à {$client->nomComplet()} (client inactif)");

        return redirect()->away($lien);
    }

    private function messageInvitation(Client $client, \App\Services\Promotions $promotions): string
    {
        $b = boutique();
        $promos = fonction('promotions')
            ? \App\Models\Promotion::enCours()->with('produit')->latest('id')->take(3)->get()->filter(fn ($p) => $p->produit)
                ->map(fn ($p) => ['nom' => $p->produit->designation, 'prix' => \App\Support\Tva::prixClient($promotions->prix($p->produit), $p->produit),
                    'avant' => \App\Support\Tva::prixClient($p->produit->prix_vente, $p->produit)])
                ->filter(fn ($p) => $p['prix'] && $p['prix'] < $p['avant'])
            : collect();

        return 'Bonjour '.($client->prenom ?: $client->nomComplet()).",\n\n"
            ."Cela fait un moment que nous ne vous avons pas vu chez {$b->nom} ! "
            .($promos->isNotEmpty()
                ? "En ce moment :\n".$promos->map(fn ($p) => '• '.$p['nom'].' : '.gnf($p['prix']).' au lieu de '.gnf($p['avant']))->implode("\n")."\n"
                : "Nous avons reçu de nouveaux produits et serons heureux de vous accueillir.\n")
            .($b->vitrine_active && fonction('vitrine') ? "\nCommandez aussi en ligne : ".route('vitrine.index', $b->slug)."\n" : '')
            ."\nÀ très bientôt,\n{$b->nom}".($b->telephone ? " — {$b->telephone}" : '')
            ."\n(Répondez STOP pour ne plus recevoir nos messages.)";
    }

    /** Fiches probablement en double (même nom ou même téléphone), à fusionner. */
    public function doublons(\App\Services\FusionClients $fusion)
    {
        $groupes = $fusion->doublonsProbables();
        $ids = $groupes->flatten()->pluck('id');
        $achats = \App\Models\Vente::validees()->whereIn('client_id', $ids)->selectRaw('client_id, COUNT(*) as nb, MAX(date_vente) as derniere')
            ->groupBy('client_id')->get()->keyBy('client_id');
        $dettes = \App\Models\Vente::avecReste()->whereIn('client_id', $ids)->selectRaw('client_id, SUM(total_ttc - montant_paye) as du')
            ->groupBy('client_id')->pluck('du', 'client_id');

        return view('clients.doublons', compact('groupes', 'achats', 'dettes'));
    }

    public function fusionner(Request $request, \App\Services\FusionClients $fusion)
    {
        $d = $request->validate([
            'garde_id' => ['required', 'integer'],
            'doublons' => ['required', 'array', 'min:1'],
            'doublons.*' => ['integer'],
        ], ['doublons.required' => 'Cochez au moins une fiche à fusionner.']);
        $garde = Client::findOrFail($d['garde_id']);
        $n = 0;
        foreach (array_diff($d['doublons'], [$garde->id]) as $id) {
            $garde = $fusion->fusionner($garde, Client::findOrFail($id), $request->user());
            $n++;
        }
        if (! $n) {
            return back()->with('erreur', 'Cochez au moins une autre fiche que celle à conserver.');
        }

        return redirect()->route('clients.show', $garde)->with('succes', "{$n} fiche(s) fusionnée(s) dans celle de {$garde->nomComplet()} : achats, crédits, points et avoirs sont regroupés ici.");
    }

    public function export(Request $request)
    {
        // Dettes calculées en une requête groupée (et non une par client)
        $dettes = \App\Models\Vente::avecReste()->whereNotNull('client_id')
            ->selectRaw('client_id, SUM(total_ttc - montant_paye) as du')->groupBy('client_id')->pluck('du', 'client_id');
        $lignes = Client::recherche($request->q)->orderBy('nom')->get()->map(fn (Client $c) => [
            'code' => $c->code, 'nom' => $c->nomComplet(), 'telephone' => numero_affiche($c->telephone),
            'adresse' => $c->residence(), 'du' => (int) ($dettes[$c->id] ?? 0),
        ])->all();

        return Tableau::telecharger($request->get('format', 'excel'), 'Clients',
            ['code' => 'Code', 'nom' => 'Nom', 'telephone' => 'Téléphone', 'adresse' => 'Adresse de résidence', 'du' => 'Reste à payer'], $lignes, ['du']);
    }

    public function create(Request $request)
    {
        return view('clients.form', ['client' => new Client, 'retour' => $request->retour]);
    }

    public function store(Request $request)
    {
        $client = Client::create($this->valider($request));
        JournalActivite::noter('client', "Ajout du client {$client->nomComplet()}");

        if ($request->expectsJson()) {
            return response()->json(['id' => $client->id, 'nom' => $client->nomComplet(), 'telephone' => $client->telephone, 'code' => $client->code]);
        }

        return redirect()->route('clients.show', $client)->with('succes', "Client {$client->nomComplet()} ajouté.");
    }

    public function show(Client $client)
    {
        return view('clients.show', [
            'client' => $client,
            'ventes' => $client->ventes()->latest('date_vente')->paginate(15),
            'paiements' => $client->paiements()->latest('date_paiement')->limit(10)->get(),
            'du' => $client->soldeDu(),
            'totalAchats' => (int) $client->ventes()->validees()->sum('total_ttc'),
            'dernierAchat' => $client->ventes()->validees()->max('date_vente'),
            'avoirs' => \App\Models\Avoir::where('client_id', $client->id)->with(['vente', 'retour'])->latest('id')->limit(10)->get(),
            'prixNegocies' => \App\Models\PrixClient::where('client_id', $client->id)->with('produit')->get()->sortBy(fn ($p) => $p->produit?->designation)->values(),
            // Produits proposés pour un nouveau prix convenu (seulement pour qui peut accorder des remises)
            'produitsPrix' => auth()->user()->aPermission('ventes.remise')
                ? \App\Models\Produit::where('actif', true)->orderBy('designation')->get(['id', 'designation', 'prix_vente', 'prix_achat', 'unite']) : collect(),
        ]);
    }

    /** Relevé de compte (PDF) : achats, versements, retours et solde progressif sur la période. */
    public function releve(Request $request, Client $client, ReleveClient $releve)
    {
        $d = $request->validate(['du' => ['nullable', 'date'], 'au' => ['nullable', 'date', 'after_or_equal:du']]);
        $r = $releve->construire($client, isset($d['du']) ? Carbon::parse($d['du']) : null, isset($d['au']) ? Carbon::parse($d['au']) : null);

        return Pdf::loadView('pdf.releve-client', $r + ['client' => $client, 'boutique' => boutique()])
            ->setPaper('a4')->stream('releve-'.$client->code.'.pdf');
    }

    /**
     * Relance d'un client débiteur par WhatsApp : message poli, montant exact, plus ancienne dette.
     * La relance est datée sur la fiche du client pour éviter de le harceler.
     */
    public function relancer(Client $client)
    {
        $du = $client->soldeDu();
        $lien = $du > 0 ? lien_whatsapp($client->telephone, $this->messageRelance($client, $du)) : null;
        if (! $lien) {
            return back()->with('erreur', $du > 0 ? "{$client->nomComplet()} n'a pas de numéro de téléphone valide." : "{$client->nomComplet()} ne doit rien.");
        }
        $client->forceFill(['derniere_relance_le' => now()])->save();
        JournalActivite::noter('client', "Relance WhatsApp de {$client->nomComplet()} pour ".gnf($du));

        return redirect()->away($lien);
    }

    private function messageRelance(Client $client, int $du): string
    {
        $b = boutique();
        $ancienne = $client->ventes()->avecReste()->orderBy('date_vente')->first();
        // Échéance la plus proche : dépassée (retard) ou à venir (rappel)
        $prochaine = $client->ventes()->avecReste()->whereNotNull('echeance')->orderBy('echeance')->first();
        $echeance = match (true) {
            ! $prochaine => '',
            $prochaine->creditEnRetard() => 'La date de paiement convenue ('.$prochaine->echeance->format('d/m/Y').") est dépassée.\n",
            default => 'Date de paiement convenue : avant le '.$prochaine->echeance->format('d/m/Y').".\n",
        };

        return "Bonjour {$client->nomComplet()},\n\n"
            ."Sauf erreur de notre part, il reste ".gnf($du)." à régler chez {$b->nom}"
            .($ancienne ? ' (achat le plus ancien : '.$ancienne->date_vente->format('d/m/Y').')' : '').".\n"
            .$echeance
            ."Merci de passer régler dès que possible, ou de nous indiquer une date.\n\n"
            ."Si vous avez déjà payé, merci de ne pas tenir compte de ce message.\n{$b->nom}".($b->telephone ? " — {$b->telephone}" : '');
    }

    public function edit(Client $client)
    {
        return view('clients.form', ['client' => $client, 'retour' => null]);
    }

    public function update(Request $request, Client $client)
    {
        $client->update($this->valider($request, $client));

        return redirect()->route('clients.show', $client)->with('succes', 'Client mis à jour.');
    }

    public function destroy(Client $client)
    {
        if ($client->soldeDu() > 0) {
            return back()->with('erreur', 'Ce client a encore un crédit de '.gnf($client->soldeDu()).' : encaissez-le avant de le supprimer.');
        }
        $client->delete();

        return redirect()->route('clients.index')->with('succes', 'Client supprimé.');
    }

    private function valider(Request $request, ?Client $client = null): array
    {
        $request->merge([
            'telephone' => trim((string) $request->telephone) ?: null,
            'plafond_credit' => $request->filled('plafond_credit') ? montant_saisi($request->plafond_credit) : null,
        ]);

        return array_merge($request->validate([
            'nom' => ['required', 'string', 'max:80'],
            'prenom' => ['nullable', 'string', 'max:80'],
            // Un numéro = un client : évite les doublons et les crédits éparpillés
            'telephone' => ['nullable', 'string', 'max:30', Rule::unique('clients')->where('boutique_id', boutique()->id)
                ->whereNull('deleted_at')->ignore($client?->id)],
            'email' => ['nullable', 'email', 'max:150'],
            'adresse' => ['nullable', 'string', 'max:200'],
            'quartier' => ['nullable', 'string', 'max:100'],
            'commune' => ['nullable', 'string', 'max:100'],
            'ville' => ['nullable', 'string', 'max:100'],
            'plafond_credit' => ['nullable', 'integer', 'min:0'],
            'date_naissance' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'grossiste' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['telephone.unique' => 'Un client avec ce numéro de téléphone existe déjà.']), ['grossiste' => $request->boolean('grossiste')]);
    }
}
