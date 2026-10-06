<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfilController extends Controller
{
    public function edit(Request $request)
    {
        return view('profil', ['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $user->update($request->validate([
            'prenom' => ['required', 'string', 'max:80'],
            'nom' => ['required', 'string', 'max:80'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
        ]) + ($request->has('recevoir_nouveautes') ? ['recevoir_nouveautes' => $request->boolean('recevoir_nouveautes')] : []));

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
        \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())->delete();

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
