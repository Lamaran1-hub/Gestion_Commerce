<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Plateforme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Identité de l'éditeur : nom et logo du logiciel, coordonnées, règles de licence.
 * Affichés aux clients (connexion, licence, assistance, reçus).
 */
class ParametreController extends Controller
{
    public function edit()
    {
        return view('admin.parametres', ['valeurs' => Plateforme::tout()]);
    }

    public function update(Request $request)
    {
        $d = $request->validate([
            'nom_logiciel' => ['required', 'string', 'max:60'],
            'societe' => ['required', 'string', 'max:120'],
            'telephone' => ['nullable', 'string', 'max:60'],
            'whatsapp' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:150'],
            'adresse' => ['nullable', 'string', 'max:200'],
            'site' => ['nullable', 'string', 'max:150'],
            'infos_paiement' => ['nullable', 'string', 'max:1000'],
            'jours_grace' => ['nullable', 'integer', 'min:0', 'max:30'],
            'jours_essai' => ['nullable', 'integer', 'min:0', 'max:90'],
            'logo_logiciel' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'djomy_moyens' => ['nullable', 'array'],
            'djomy_moyens.*' => [\Illuminate\Validation\Rule::in(array_keys(\App\Support\MoyensDjomy::CATALOGUE))],
        ]);
        // Moyens Djomy : au moins un doit rester actif pour pouvoir encaisser en ligne
        if ($request->boolean('djomy_moyens_envoye')) {
            if (empty($d['djomy_moyens'])) {
                return back()->withInput()->withErrors(['djomy_moyens' => 'Gardez au moins un moyen de paiement en ligne actif.']);
            }
            $d['djomy_moyens'] = implode(',', $d['djomy_moyens']);
        } else {
            unset($d['djomy_moyens']);
        }
        unset($d['logo_logiciel']);
        $d['activation_auto'] = $request->boolean('activation_auto', true) ? '1' : '0';
        $d['jours_grace'] = (string) ($d['jours_grace'] ?? 0);
        $d['jours_essai'] = (string) ($d['jours_essai'] ?? config('gestion.jours_essai'));

        $ancien = Plateforme::get('logo');
        if ($request->hasFile('logo_logiciel')) {
            $d['logo'] = $request->file('logo_logiciel')->store('plateforme', 'public');
        } elseif ($request->boolean('retirer_logo')) {
            $d['logo'] = null;
        }
        if (array_key_exists('logo', $d) && $ancien) {
            Storage::disk('public')->delete($ancien);
        }
        Plateforme::enregistrer($d);

        return back()->with('succes', 'Informations de votre société et de votre logiciel enregistrées.');
    }
}
