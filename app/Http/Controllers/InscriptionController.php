<?php

namespace App\Http\Controllers;

use App\Services\BoutiqueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/** Une boutique s'inscrit elle-même et démarre une période d'essai. */
class InscriptionController extends Controller
{
    public function create()
    {
        abort_unless(config('gestion.inscription_ouverte'), 404);

        return view('auth.inscription');
    }

    public function store(Request $request, BoutiqueService $service)
    {
        abort_unless(config('gestion.inscription_ouverte'), 404);
        \App\Support\AntiRobot::verifier($request, 'inscription');

        $d = $request->validate([
            'boutique_nom' => ['required', 'string', 'max:120'],
            'boutique_telephone' => ['required', 'string', 'max:30'],
            'ville' => ['nullable', 'string', 'max:80'],
            'prenom' => ['required', 'string', 'max:80'],
            'nom' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $boutique = $service->creer(
            ['nom' => $d['boutique_nom'], 'telephone' => $d['boutique_telephone'], 'ville' => $d['ville'] ?? null, 'email' => $d['email']],
            ['prenom' => $d['prenom'], 'nom' => $d['nom'], 'email' => $d['email'], 'telephone' => $d['boutique_telephone'], 'password' => $d['password']],
        );

        Auth::login($boutique->utilisateurs()->first());
        $request->session()->regenerate();

        return redirect()->route('parametres.edit')
            ->with('succes', 'Votre boutique est créée. Ajoutez votre logo et vos coordonnées pour personnaliser vos factures.');
    }
}
