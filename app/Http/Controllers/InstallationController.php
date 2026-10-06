<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Plateforme;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Première mise en service : crée le compte Propriétaire (éditeur du logiciel),
 * enregistre les coordonnées de sa société et les formules par défaut.
 * Inaccessible dès qu'un propriétaire existe.
 */
class InstallationController extends Controller
{
    public function create()
    {
        abort_if(Plateforme::estInstallee(), 404);

        return view('installation');
    }

    public function store(Request $request)
    {
        abort_if(Plateforme::estInstallee(), 404);

        $d = $request->validate([
            'societe' => ['required', 'string', 'max:120'],
            'nom_logiciel' => ['required', 'string', 'max:60'],
            'societe_telephone' => ['required', 'string', 'max:60'],
            'societe_email' => ['nullable', 'email', 'max:150'],
            'prenom' => ['required', 'string', 'max:80'],
            'nom' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        Artisan::call('db:seed', ['--class' => PlanSeeder::class, '--force' => true]);
        Plateforme::enregistrer([
            'societe' => $d['societe'],
            'nom_logiciel' => $d['nom_logiciel'],
            'telephone' => $d['societe_telephone'],
            'email' => $d['societe_email'] ?? $d['email'],
        ]);
        $proprietaire = User::create([
            'prenom' => $d['prenom'], 'nom' => $d['nom'], 'email' => $d['email'], 'telephone' => $d['societe_telephone'],
            'password' => $d['password'], 'est_super_admin' => true,
        ]);

        Auth::login($proprietaire);
        $request->session()->regenerate();
        $request->session()->put('derniere_activite', now()->timestamp);

        return redirect()->route('admin.dashboard')
            ->with('succes', 'Votre espace Propriétaire est prêt. Vous pouvez maintenant enregistrer vos clients et leurs licences.');
    }
}
