<?php

namespace App\Http\Controllers;

use App\Models\Paiement;
use App\Services\PointagePaiements;
use Illuminate\Http\Request;

/** Pointage des paiements mobile money, carte, virement et chèque avec les SMS ou le relevé. */
class PointagePaiementController extends Controller
{
    public function __construct(private PointagePaiements $pointage)
    {
    }

    public function index(Request $request)
    {
        $du = $request->du ?: now()->subDays(6)->toDateString();
        $au = $request->au ?: now()->toDateString();
        $etat = in_array($request->etat, ['a_pointer', 'pointes', 'litiges', 'tous'], true) ? $request->etat : 'a_pointer';
        $mode = in_array($request->mode, PointagePaiements::MODES, true) ? $request->mode : null;

        return view('tresorerie.pointage', [
            'du' => $du, 'au' => $au, 'etat' => $etat, 'mode' => $mode,
            'paiements' => $this->pointage->requete($du, $au, $mode, $etat)->paginate(100)->withQueryString(),
            'resume' => $this->pointage->resume($du, $au),
        ]);
    }

    public function pointer(Request $request)
    {
        $d = $request->validate(['paiements' => ['required', 'array', 'min:1'], 'paiements.*' => ['integer']],
            ['paiements.required' => 'Cochez les paiements retrouvés sur vos SMS ou votre relevé.']);
        $n = $this->pointage->pointer($d['paiements'], $request->user());

        return back()->with('succes', $n ? "{$n} paiement(s) pointé(s) : l'argent est bien arrivé." : 'Ces paiements étaient déjà pointés.');
    }

    public function litige(Request $request, Paiement $encaissement)
    {
        $request->validate(['note' => ['required', 'string', 'max:190'], 'note_autre' => ['nullable', 'string', 'max:150']],
            ['note.required' => 'Indiquez pourquoi ce paiement est introuvable.']);
        $this->pointage->litige($encaissement, mb_substr(choix_autre('note'), 0, 190), $request->user());

        return back()->with('succes', 'Paiement mis en litige : parlez-en avec '.($encaissement->caissier?->nomComplet() ?? 'le caissier').' et vérifiez avec le client.');
    }

    public function annuler(Request $request, Paiement $encaissement)
    {
        $this->pointage->annuler($encaissement, $request->user());

        return back()->with('succes', 'Le paiement est de nouveau à pointer.');
    }
}
