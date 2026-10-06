<?php

namespace App\Http\Controllers;

use App\Models\JournalActivite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Identité de la boutique : logo, couleurs (jusqu'à 3), coordonnées et mentions des factures. */
class ParametreController extends Controller
{
    public function edit()
    {
        return view('parametres', ['boutique' => boutique()]);
    }

    public function update(Request $request)
    {
        $boutique = boutique();
        if ($request->has('fidelite_minimum')) {
            $request->merge(['fidelite_minimum' => $request->filled('fidelite_minimum') ? montant_saisi($request->fidelite_minimum) : null]);
        }
        if ($request->has('objectif_mensuel')) {
            $request->merge(['objectif_mensuel' => $request->filled('objectif_mensuel') ? montant_saisi($request->objectif_mensuel) : null]);
        }
        if ($request->has('plafond_credit_defaut')) {
            $request->merge(['plafond_credit_defaut' => $request->filled('plafond_credit_defaut') ? montant_saisi($request->plafond_credit_defaut) : null]);
        }
        $d = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'telephone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:150'],
            'adresse' => ['nullable', 'string', 'max:200'],
            'ville' => ['nullable', 'string', 'max:80'],
            'rccm' => ['nullable', 'string', 'max:60'],
            'nif' => ['nullable', 'string', 'max:60'],
            'tva_taux' => ['required', 'numeric', 'min:0', 'max:50'],
            'pied_facture' => ['nullable', 'string', 'max:500'],
            'couleur' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'couleur_2' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'couleur_3' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            // Règles de gestion
            'remise_max_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'plafond_credit_defaut' => ['nullable', 'integer', 'min:0'],
            'delai_credit_jours' => ['nullable', 'integer', 'min:1', 'max:365'],
            'delai_annulation_heures' => ['nullable', 'integer', 'min:1', 'max:720'],
            'delai_retour_jours' => ['nullable', 'integer', 'min:1', 'max:365'],
            'validite_devis_jours' => ['nullable', 'integer', 'min:1', 'max:180'],
            'methode_cout' => ['nullable', 'in:dernier,cmp'],
            'couverture_stock_jours' => ['nullable', 'integer', 'min:1', 'max:180'],
            'alerte_peremption_jours' => ['nullable', 'integer', 'min:1', 'max:365'],
            'fidelite_taux' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'fidelite_minimum' => ['nullable', 'integer', 'min:0'],
            'periode_verrouillee_jusquau' => ['nullable', 'date', 'before:today'],
            'vitrine_message' => ['nullable', 'string', 'max:300'],
            'objectif_mensuel' => ['nullable', 'integer', 'min:0'],
            'cadeau_anniversaire' => ['nullable', 'string', 'max:200'],
        ]);
        // Clôturer ou rouvrir une période comptable est réservé à l'administrateur de la boutique
        $verrouActuel = $boutique->periode_verrouillee_jusquau?->toDateString();
        $verrouDemande = empty($d['periode_verrouillee_jusquau']) ? null : \Carbon\Carbon::parse($d['periode_verrouillee_jusquau'])->toDateString();
        if (! $request->user()->role?->systeme) {
            $verrouDemande = $verrouActuel;   // champ affiché en lecture seule pour les autres rôles
        } elseif ($verrouDemande !== $verrouActuel) {
            JournalActivite::noter('parametres', $verrouDemande
                ? 'Période clôturée jusqu\'au '.\Carbon\Carbon::parse($verrouDemande)->format('d/m/Y')
                : 'Période comptable rouverte (plus aucun verrou)');
        }
        $d['periode_verrouillee_jusquau'] = $verrouDemande;
        $d['couverture_stock_jours'] = $d['couverture_stock_jours'] ?? 14;
        $d['alerte_peremption_jours'] = $d['alerte_peremption_jours'] ?? 30;
        if ($request->has('fidelite_taux')) {
            $d['fidelite_taux'] = $d['fidelite_taux'] ?? 0;
            $d['fidelite_minimum'] = $d['fidelite_minimum'] ?? 0;
        } else {
            unset($d['fidelite_taux'], $d['fidelite_minimum']); // champs absents : formule sans fidélité, on ne touche à rien
        }
        $d['tva_active'] = $request->boolean('tva_active');
        $d['prix_ttc'] = $request->boolean('prix_ttc');   // prix de vente TVA comprise (la TVA est extraite, pas ajoutée)
        $d['vente_a_perte'] = $request->boolean('vente_a_perte');
        if ($request->has('resume_quotidien')) {
            $d['resume_quotidien'] = $request->boolean('resume_quotidien');
        }
        // Vitrine : réglages présents seulement si la formule l'inclut
        if ($request->has('vitrine_active') && fonction('vitrine')) {
            $d['vitrine_active'] = $request->boolean('vitrine_active');
            $d['vitrine_stock_visible'] = $request->boolean('vitrine_stock_visible');
        } else {
            unset($d['vitrine_message']);
        }
        if (! $request->has('cadeau_anniversaire')) {
            unset($d['cadeau_anniversaire']);
        }
        if (! $request->has('objectif_mensuel')) {
            unset($d['objectif_mensuel']);
        } elseif (($d['objectif_mensuel'] ?? null) === 0) {
            $d['objectif_mensuel'] = null;   // 0 = pas d'objectif
        }
        $d['methode_cout'] = $d['methode_cout'] ?? 'dernier';
        // Couleurs secondaires facultatives : une couleur retirée libère sa place
        $autres = array_values(array_filter([$d['couleur_2'] ?? null, $d['couleur_3'] ?? null]));
        $d['couleur_2'] = $autres[0] ?? null;
        $d['couleur_3'] = $autres[1] ?? null;

        unset($d['logo']);
        if ($request->boolean('supprimer_logo') && $boutique->logo) {
            Storage::disk('public')->delete($boutique->logo);
            $d['logo'] = null;
        }
        if ($request->hasFile('logo')) {
            if ($boutique->logo) {
                Storage::disk('public')->delete($boutique->logo);
            }
            $d['logo'] = $request->file('logo')->store('boutiques/'.$boutique->id, 'public');
        }

        // Passage prix hors taxe ⇄ prix TVA comprise (TVA active avant et après) : les prix peuvent être ajustés
        // pour que les clients paient le même montant qu'avant
        $changementMode = $boutique->tva_active && $d['tva_active'] && (bool) $boutique->prix_ttc !== $d['prix_ttc'];
        $boutique->update($d);
        JournalActivite::noter('parametres', 'Mise à jour des paramètres de la boutique');
        $ajustes = $changementMode && $request->boolean('ajuster_prix')
            ? app(\App\Services\ConversionPrixTva::class)->convertir($boutique->fresh(), $d['prix_ttc']) : null;

        return back()->with('succes', 'Paramètres enregistrés.'.($ajustes !== null
            ? " {$ajustes} prix de vente ajustés : vos clients paient le même montant qu'avant."
            : ($changementMode ? ' Vos prix de vente n\'ont pas été modifiés : vérifiez-les.' : '')));
    }
}
