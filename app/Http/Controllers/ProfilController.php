<?php

namespace App\Http\Controllers;

use App\Models\JournalActivite;
use App\Notifications\AlerteSecuriteCompte;
use App\Support\Appareils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfilController extends Controller
{
    public function edit(Request $request)
    {
        return view('profil', ['user' => $request->user(), 'appareils' => Appareils::liste($request->user(), $request->session()->getId()),
            'connexions' => app(\App\Services\SecuriteConnexion::class)->dernieres($request->user())]);
    }

    /** Déconnecte un appareil (téléphone perdu, ordinateur partagé). */
    public function deconnecterAppareil(Request $request, string $empreinte)
    {
        if (! Appareils::deconnecter($request->user(), $empreinte)) {
            return back()->with('erreur', 'Cet appareil est déjà déconnecté.');
        }
        $this->journal($request->user()->nomComplet().' a déconnecté un de ses appareils');

        return back()->with('succes', 'Appareil déconnecté : il devra se reconnecter avec le mot de passe.');
    }

    /** Déconnecte tous les autres appareils (celui-ci reste connecté). */
    public function deconnecterAutres(Request $request)
    {
        $n = Appareils::deconnecterTous($request->user(), $request->session()->getId());
        $this->journal($request->user()->nomComplet()." a déconnecté ses autres appareils ({$n})");

        return back()->with('succes', $n ? "{$n} autre(s) appareil(s) déconnecté(s)." : 'Aucun autre appareil n\'était connecté.');
    }

    /** Journal de la boutique (le compte de l'éditeur n'a pas de boutique : rien à noter). */
    private function journal(string $description): void
    {
        if (app(\App\Support\BoutiqueCourante::class)->id() ?? auth()->user()?->boutique_id) {
            JournalActivite::noter('securite', $description);
        }
    }

    /** Prévient le titulaire d'un changement sensible ; un e-mail qui ne part pas ne bloque jamais l'opération. */
    private function alerter(string $email, string $prenom, string $evenement, Request $request): void
    {
        try {
            Notification::route('mail', $email)->notify(new AlerteSecuriteCompte($prenom, $evenement,
                Appareils::decrire($request->userAgent())['appareil'].' ('.$request->ip().')'));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $d = $request->validate([
            'prenom' => ['required', 'string', 'max:80'],
            'nom' => ['required', 'string', 'max:80'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
        ]);
        // L'e-mail sert à se connecter et à récupérer le mot de passe : le changer demande le mot de passe actuel
        $ancienEmail = $user->email;
        $emailChange = mb_strtolower(trim($d['email'])) !== mb_strtolower($ancienEmail);
        if (! $emailChange) {
            $d['email'] = $ancienEmail;   // seule la casse diffère : rien ne change
        } else {
            $request->validate(['mot_de_passe_email' => ['required', 'current_password']], [
                'mot_de_passe_email.required' => 'Pour changer d\'adresse e-mail, saisissez votre mot de passe actuel.',
                'mot_de_passe_email.current_password' => 'Le mot de passe actuel est incorrect.',
            ]);
        }
        $user->update($d + ($request->has('recevoir_nouveautes') ? ['recevoir_nouveautes' => $request->boolean('recevoir_nouveautes')] : []));
        if ($emailChange) {
            $this->journal("{$user->nomComplet()} a changé son adresse e-mail");
            $this->alerter($ancienEmail, $user->prenom, 'L\'adresse e-mail de votre compte a été remplacée par '.$user->email, $request);
        }

        return back()->with('succes', 'Profil mis à jour.');
    }

    /** Désabonnement des nouveautés en un clic depuis un e-mail (lien signé, sans connexion). */
    public function desabonner(\App\Models\User $user)
    {
        $user->forceFill(['recevoir_nouveautes' => false])->save();

        return view('emails.desabonne', ['user' => $user]);
    }

    public function motDePasse(Request $request)
    {
        $request->validate([
            'mot_de_passe_actuel' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], ['mot_de_passe_actuel.current_password' => 'Le mot de passe actuel est incorrect.']);

        $request->user()->update(['password' => $request->password, 'doit_changer_mot_de_passe' => false]);
        // Par sécurité, les autres appareils connectés à ce compte sont déconnectés
        Appareils::deconnecterTous($request->user(), $request->session()->getId());
        $this->alerter($request->user()->email, $request->user()->prenom, 'Le mot de passe de votre compte a été changé', $request);

        return redirect()->route($request->user()->est_super_admin ? 'admin.dashboard' : 'dashboard')
            ->with('succes', 'Mot de passe modifié.');
    }

    /**
     * « Me rappeler plus tard » : le rappel du mot de passe provisoire disparaît
     * jusqu'à la prochaine connexion (la session est renouvelée à chaque connexion).
     */
    public function reporterRappel(Request $request)
    {
        $request->session()->put('rappel_mdp_reporte', true);

        return $request->expectsJson() ? response()->noContent() : back();
    }

    /** Marque toutes les notifications de l'utilisateur comme lues. */
    public function notificationsLues(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
