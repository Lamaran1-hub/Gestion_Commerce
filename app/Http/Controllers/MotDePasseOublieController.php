<?php

namespace App\Http\Controllers;

use App\Models\JournalActivite;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as RegleMotDePasse;

/**
 * Mot de passe oublié, selon le schéma standard (OWASP) :
 * 1. l'utilisateur donne son e-mail ; la réponse est identique que le compte existe ou non ;
 * 2. un lien à usage unique, valable 60 minutes, est envoyé par e-mail ;
 * 3. le nouveau mot de passe remplace l'ancien et toutes les sessions ouvertes sont fermées.
 */
class MotDePasseOublieController extends Controller
{
    public function create()
    {
        return view('auth.mot-de-passe-oublie');
    }

    public function envoyer(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        $email = trim($request->email);

        // Compte suspendu : aucun lien (mais même réponse, pour ne rien révéler)
        if (User::where('email', $email)->where('actif', true)->exists()) {
            try {
                Password::sendResetLink(['email' => $email]);
            } catch (\Throwable $e) {
                Log::error('Lien de réinitialisation non envoyé', ['erreur' => $e->getMessage()]);
            }
        }

        return back()->with('succes', "Si un compte actif existe pour {$email}, un lien de réinitialisation vient d'y être envoyé. "
            .'Il est valable '.config('auth.passwords.users.expire').' minutes. Pensez à regarder dans les courriers indésirables.');
    }

    public function formulaire(Request $request, string $token)
    {
        return view('auth.reinitialiser-mot-de-passe', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reinitialiser(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', RegleMotDePasse::min(8)->letters()->numbers()],
        ]);

        $statut = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $motDePasse) {
            $user->forceFill([
                'password' => $motDePasse,
                'doit_changer_mot_de_passe' => false,
                'remember_token' => Str::random(60),
            ])->save();
            // Déconnecte tous les appareils où le compte était ouvert
            DB::table('sessions')->where('user_id', $user->id)->delete();
            if ($user->boutique_id) {
                JournalActivite::noterPour($user->boutique_id, 'securite', $user->nomComplet().' a réinitialisé son mot de passe (lien par e-mail)');
            }
            event(new PasswordReset($user));
        });

        if ($statut !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'Ce lien n\'est plus valable (déjà utilisé, expiré ou adresse différente). Faites une nouvelle demande.']);
        }

        return redirect()->route('login')->with('succes', 'Votre mot de passe a été changé. Connectez-vous avec le nouveau.');
    }
}
