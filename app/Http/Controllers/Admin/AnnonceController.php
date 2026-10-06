<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Annonce;
use App\Models\Boutique;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Annonces du propriétaire aux utilisateurs : nouveautés, maintenance, messages importants. */
class AnnonceController extends Controller
{
    public function index()
    {
        return view('admin.annonces.index', [
            'annonces' => Annonce::with('boutique')->withCount('lecteurs')->latest()->paginate(20),
            'nbUtilisateurs' => User::where('est_super_admin', false)->where('actif', true)->count(),
        ]);
    }

    public function create()
    {
        return view('admin.annonces.form', ['annonce' => new Annonce(['type' => 'nouveaute']), 'boutiques' => Boutique::orderBy('nom')->get(['id', 'nom'])]);
    }

    public function store(Request $request)
    {
        $annonce = Annonce::create($this->valider($request) + ['user_id' => $request->user()->id]);
        $emails = $this->envoyerParEmail($request, $annonce);

        return redirect()->route('admin.annonces.index')->with('succes', ($annonce->publiee_le
            ? 'Annonce publiée : vos utilisateurs la verront à leur prochaine page.' : 'Annonce enregistrée en brouillon.')
            .($emails !== null ? " E-mail envoyé à {$emails} administrateur(s)." : ''));
    }

    public function edit(Annonce $annonce)
    {
        return view('admin.annonces.form', ['annonce' => $annonce, 'boutiques' => Boutique::orderBy('nom')->get(['id', 'nom'])]);
    }

    public function update(Request $request, Annonce $annonce)
    {
        $annonce->update($this->valider($request, $annonce));
        $emails = $this->envoyerParEmail($request, $annonce);

        return redirect()->route('admin.annonces.index')->with('succes', 'Annonce mise à jour.'
            .($emails !== null ? " E-mail envoyé à {$emails} administrateur(s)." : ''));
    }

    public function destroy(Annonce $annonce)
    {
        $annonce->delete();

        return back()->with('succes', 'Annonce supprimée.');
    }

    /**
     * Envoi par e-mail d'une annonce publiée, une seule fois : aux administrateurs actifs de la boutique visée
     * (ou de toutes les boutiques non suspendues), sauf ceux désabonnés des nouveautés.
     *
     * @return int|null nombre d'e-mails partis, null si aucun envoi demandé
     */
    private function envoyerParEmail(Request $request, Annonce $annonce): ?int
    {
        if (! $request->boolean('envoyer_email') || ! $annonce->publiee_le || $annonce->email_envoye_le) {
            return null;
        }
        $boutiques = $annonce->boutique_id ? [$annonce->boutique_id]
            : Boutique::where('statut', '!=', 'suspendu')->pluck('id')->all();
        $admins = \App\Models\User::whereIn('boutique_id', $boutiques)->where('actif', true)
            ->whereHas('role', fn ($q) => $q->where('systeme', true))->get();
        $partis = \App\Support\Courrier::envoyer($admins, new \App\Notifications\Nouveaute($annonce), 'nouveaute', $annonce->boutique_id, nouveaute: true);
        $annonce->forceFill(['email_envoye_le' => now()])->save();

        return $partis;
    }

    private function valider(Request $request, ?Annonce $annonce = null): array
    {
        $d = $request->validate([
            'titre' => ['required', 'string', 'max:150'],
            'contenu' => ['required', 'string', 'max:5000'],
            'type' => ['required', Rule::in(array_keys(Annonce::TYPES))],
            'boutique_id' => ['nullable', 'exists:boutiques,id'],
            'expire_le' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        // « Publier » rend l'annonce visible tout de suite ; sinon elle reste en brouillon
        $d['publiee_le'] = $request->boolean('publier') ? ($annonce?->publiee_le ?? now()) : null;

        return $d;
    }
}
