<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\JournalActivite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Utilisateurs de toutes les boutiques : suspension et mot de passe oublié. */
class UtilisateurController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.utilisateurs.index', [
            'utilisateurs' => User::where('est_super_admin', false)->with(['boutique', 'role'])
                ->when($request->q, fn ($q) => $q->where(fn ($s) => $s->where('prenom', 'like', "%{$request->q}%")
                    ->orWhere('nom', 'like', "%{$request->q}%")->orWhere('email', 'like', "%{$request->q}%")
                    ->orWhere('telephone', 'like', "%{$request->q}%")))
                ->when($request->boutique_id, fn ($q) => $q->where('boutique_id', $request->boutique_id))
                ->when($request->etat === 'actif', fn ($q) => $q->where('actif', true))
                ->when($request->etat === 'suspendu', fn ($q) => $q->where('actif', false))
                ->orderBy('prenom')->paginate(30)->withQueryString(),
            'boutiques' => Boutique::orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    /** Suspend ou réactive un utilisateur ; une suspension le déconnecte immédiatement. */
    public function statut(User $utilisateur)
    {
        abort_if($utilisateur->est_super_admin, 403);
        $utilisateur->update(['actif' => ! $utilisateur->actif]);
        if (! $utilisateur->actif) {
            DB::table('sessions')->where('user_id', $utilisateur->id)->delete();
            $utilisateur->forceFill(['remember_token' => null])->save();
        }
        JournalActivite::noterPour($utilisateur->boutique_id, 'utilisateur',
            $utilisateur->nomComplet().($utilisateur->actif ? ' réactivé(e)' : ' suspendu(e)').' par le propriétaire');

        return back()->with('succes', $utilisateur->nomComplet().($utilisateur->actif ? ' peut de nouveau se connecter.' : ' est suspendu(e) et a été déconnecté(e).'));
    }

    /** Génère un mot de passe provisoire (affiché une seule fois) à transmettre à l'utilisateur. */
    public function reinitialiser(User $utilisateur)
    {
        abort_if($utilisateur->est_super_admin, 403);
        $motDePasse = Str::password(10, symbols: false);
        $utilisateur->update(['password' => $motDePasse, 'doit_changer_mot_de_passe' => true]);
        DB::table('sessions')->where('user_id', $utilisateur->id)->delete();
        JournalActivite::noterPour($utilisateur->boutique_id, 'utilisateur', 'Mot de passe provisoire créé pour '.$utilisateur->nomComplet().' par le propriétaire');

        return back()->with('identifiants', ['email' => $utilisateur->email, 'mot_de_passe' => $motDePasse,
            'nom' => $utilisateur->prenom, 'telephone' => $utilisateur->telephone])
            ->with('succes', "Nouveau mot de passe provisoire créé pour {$utilisateur->nomComplet()}.");
    }
}
