<?php

namespace App\Http\Controllers;

use App\Exceptions\OperationRefusee;
use App\Models\JournalActivite;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UtilisateurController extends Controller
{
    public function index()
    {
        return view('utilisateurs.index', [
            'utilisateurs' => User::where('boutique_id', boutique()->id)->with('role')->orderBy('nom')->get(),
            'max' => boutique()->plan?->max_utilisateurs,
        ]);
    }

    public function create()
    {
        $this->verifierLimite();

        return view('utilisateurs.form', ['utilisateur' => new User(['actif' => true]), 'roles' => Role::orderBy('nom')->get()]);
    }

    public function store(Request $request)
    {
        $this->verifierLimite();
        $d = $this->valider($request);
        $user = User::create($d + ['boutique_id' => boutique()->id, 'doit_changer_mot_de_passe' => true]);
        JournalActivite::noter('utilisateur', "Création du compte de {$user->nomComplet()}");

        return redirect()->route('utilisateurs.index')
            ->with('succes', "Compte de {$user->nomComplet()} créé. Il devra changer son mot de passe à la première connexion.");
    }

    public function edit(User $utilisateur)
    {
        $this->verifierBoutique($utilisateur);

        return view('utilisateurs.form', ['utilisateur' => $utilisateur, 'roles' => Role::orderBy('nom')->get()]);
    }

    public function update(Request $request, User $utilisateur)
    {
        $this->verifierBoutique($utilisateur);
        $d = $this->valider($request, $utilisateur);
        if (empty($d['password'])) {
            unset($d['password']);
        } else {
            $d['doit_changer_mot_de_passe'] = $utilisateur->id !== auth()->id();
        }
        // Son propre compte : on ne peut ni se désactiver ni changer son rôle (on perdrait l'accès sur-le-champ)
        if ($utilisateur->id === auth()->id()) {
            if (empty($d['actif'])) {
                throw new OperationRefusee('Vous ne pouvez pas désactiver votre propre compte : demandez-le à un autre administrateur.');
            }
            if ((int) $d['role_id'] !== (int) $utilisateur->role_id) {
                throw new OperationRefusee('Vous ne pouvez pas changer votre propre rôle : demandez-le à un autre administrateur.');
            }
        }
        $this->protegerDernierAdmin($utilisateur, $d);
        if (! $utilisateur->actif && ! empty($d['actif'])) {
            $this->verifierLimite(); // réactiver un compte occupe une place de la formule
        }
        $utilisateur->update($d);
        if ($utilisateur->wasChanged('actif') && ! $utilisateur->actif) {
            \App\Support\Appareils::deconnecterTous($utilisateur);
        }
        // Nouveau mot de passe donné par l'administrateur (téléphone perdu, employé qui part) : ses autres appareils sont déconnectés
        if (isset($d['password']) && $utilisateur->id !== auth()->id()) {
            \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $utilisateur->id)->delete();
            JournalActivite::noter('securite', "Mot de passe de {$utilisateur->nomComplet()} changé par l'administrateur : ses appareils sont déconnectés");
        }

        return redirect()->route('utilisateurs.index')->with('succes', 'Utilisateur mis à jour.');
    }

    /** Téléphone perdu ou volé, ordinateur resté ouvert : le compte est fermé sur tous ses appareils. */
    public function deconnecter(User $utilisateur)
    {
        $this->verifierBoutique($utilisateur);
        if ($utilisateur->id === auth()->id()) {
            throw new OperationRefusee('Pour vos propres appareils, utilisez « Mon profil » → Appareils connectés.');
        }
        $n = \App\Support\Appareils::deconnecterTous($utilisateur);
        JournalActivite::noter('securite', "{$utilisateur->nomComplet()} déconnecté de tous ses appareils ({$n}) par l'administrateur");

        return back()->with('succes', "{$utilisateur->nomComplet()} est déconnecté de tous ses appareils ({$n}). Changez aussi son mot de passe si l'appareil a été perdu ou volé.");
    }

    public function destroy(User $utilisateur)
    {
        $this->verifierBoutique($utilisateur);
        if ($utilisateur->id === auth()->id()) {
            throw new OperationRefusee('Vous ne pouvez pas supprimer votre propre compte.');
        }
        $this->protegerDernierAdmin($utilisateur, ['actif' => false]);
        // On désactive au lieu de supprimer : les ventes gardent le nom du vendeur
        $utilisateur->update(['actif' => false]);
        \App\Support\Appareils::deconnecterTous($utilisateur);   // plus aucune session ouverte, même inactive
        JournalActivite::noter('utilisateur', "Désactivation du compte de {$utilisateur->nomComplet()}");

        return back()->with('succes', "Le compte de {$utilisateur->nomComplet()} est désactivé.");
    }

    private function valider(Request $request, ?User $user = null): array
    {
        if ($request->has('objectif_mensuel')) {
            $request->merge(['objectif_mensuel' => $request->filled('objectif_mensuel') ? montant_saisi($request->objectif_mensuel) : null]);
        }
        $d = $request->validate([
            'prenom' => ['required', 'string', 'max:80'],
            'nom' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($user?->id)],
            'telephone' => ['nullable', 'string', 'max:30'],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('boutique_id', boutique()->id)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(8)->letters()->numbers()],
            'objectif_mensuel' => ['nullable', 'integer', 'min:0'],
            'commission_pct' => ['nullable', 'numeric', 'min:0', 'max:50'],
        ]);
        $d['actif'] = $request->boolean('actif');
        // Objectif et commission : réglés seulement avec la gestion d'équipe (sinon on n'y touche pas)
        if (! fonction('equipe') || ! $request->has('objectif_mensuel')) {
            unset($d['objectif_mensuel'], $d['commission_pct']);
        } else {
            $d['objectif_mensuel'] = ($d['objectif_mensuel'] ?? null) ?: null;
            $d['commission_pct'] = isset($d['commission_pct']) && (float) $d['commission_pct'] > 0 ? $d['commission_pct'] : null;
        }

        return $d;
    }

    private function verifierBoutique(User $user): void
    {
        abort_unless($user->boutique_id === boutique()->id, 404);
    }

    private function verifierLimite(): void
    {
        boutique()->verifierLimite('utilisateurs');
    }

    /** Une boutique garde toujours au moins un administrateur actif. */
    private function protegerDernierAdmin(User $user, array $modifs): void
    {
        if (! $user->estAdministrateurBoutique()) {
            return;
        }
        $perdAdmin = (isset($modifs['actif']) && ! $modifs['actif'])
            || (isset($modifs['role_id']) && (int) $modifs['role_id'] !== $user->role_id && ! Role::find($modifs['role_id'])?->systeme);
        $autresAdmins = User::where('boutique_id', $user->boutique_id)->where('actif', true)->where('id', '!=', $user->id)
            ->whereHas('role', fn ($q) => $q->where('systeme', true))->count();

        if ($perdAdmin && $autresAdmins === 0) {
            throw new OperationRefusee('La boutique doit garder au moins un administrateur actif.');
        }
    }
}
