<?php

namespace App\Http\Controllers;

use App\Models\Annonce;
use Illuminate\Http\Request;

/** Annonces du propriétaire du logiciel, vues par les utilisateurs d'une boutique. */
class NouveauteController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $annonces = Annonce::pourUtilisateur($user)->latest('publiee_le')->paginate(15);
        $nonLues = Annonce::pourUtilisateur($user)->nonLuesPar($user)->pluck('id');

        // Ouvrir la page vaut lecture des annonces affichées
        $user->annoncesLues()->syncWithoutDetaching($annonces->pluck('id')->mapWithKeys(fn ($id) => [$id => ['lue_le' => now()]])->all());

        return view('nouveautes', ['annonces' => $annonces, 'nonLues' => $nonLues]);
    }

    public function lire(Request $request, Annonce $annonce)
    {
        abort_unless(Annonce::pourUtilisateur($request->user())->whereKey($annonce->id)->exists(), 404);
        $request->user()->annoncesLues()->syncWithoutDetaching([$annonce->id => ['lue_le' => now()]]);

        return back();
    }
}
