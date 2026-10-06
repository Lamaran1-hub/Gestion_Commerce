<?php

namespace App\Http\Controllers;

use App\Models\OperationTresorerie;
use App\Services\Tresorerie;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Où est l'argent ? Soldes par compte, transferts, apports/retraits de l'exploitant, constats de solde. */
class TresorerieController extends Controller
{
    public function index(Tresorerie $tresorerie)
    {
        $soldes = $tresorerie->soldes();

        return view('tresorerie.index', [
            'soldes' => $soldes,
            'total' => array_sum(array_column($soldes, 'solde')),
            'operations' => OperationTresorerie::with('auteur')->latest('date_operation')->latest('id')->paginate(20),
        ]);
    }

    public function store(Request $request, Tresorerie $tresorerie)
    {
        $request->merge(['montant' => montant_saisi($request->montant), 'frais' => $request->filled('frais') ? montant_saisi($request->frais) : 0]);
        $comptes = array_keys(Tresorerie::comptes());
        $d = $request->validate([
            'type' => ['required', Rule::in(array_keys(OperationTresorerie::TYPES))],
            'compte_source' => ['nullable', 'required_if:type,transfert,retrait', Rule::in($comptes)],
            'compte_destination' => ['nullable', 'required_if:type,transfert,apport,constat', Rule::in($comptes)],
            'montant' => ['required', 'integer', 'min:0'],
            'frais' => ['nullable', 'integer', 'min:0'],
            'motif' => ['nullable', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $o = $tresorerie->enregistrer($d, $request->user());

        $message = match ($o->type) {
            'constat' => $o->ecart === 0 ? 'Solde confirmé : aucun écart.' : 'Solde enregistré. Écart constaté : '.($o->ecart > 0 ? '+' : '−').gnf(abs($o->ecart)).'.',
            default => $o->libelle().' de '.gnf($o->montant).' enregistré'.($o->frais ? ' (frais '.gnf($o->frais).')' : '').'.',
        };

        return back()->with($o->type === 'constat' && $o->ecart ? 'erreur' : 'succes', $message);
    }

    /** Journal d'un compte sur une période : chaque entrée et sortie d'argent. */
    public function journal(Request $request, string $compte, Tresorerie $tresorerie)
    {
        abort_unless(array_key_exists($compte, Tresorerie::comptes()), 404);
        $du = $request->date('du') ?? now()->subDays(30);
        $au = $request->date('au') ?? now();
        $debut = $du->copy()->startOfDay();
        $fin = $au->copy()->endOfDay();

        $soldeDebut = $tresorerie->soldes($debut->copy()->subSecond())[$compte]['solde'];
        $lignes = $tresorerie->lignes($compte, $debut->copy()->subSecond(), $fin);
        // Un constat remet le solde au montant réellement compté : on l'insère dans le journal
        $constats = OperationTresorerie::where('type', 'constat')->where('compte_destination', $compte)
            ->whereBetween('date_operation', [$debut, $fin])->get()
            ->map(fn ($o) => ['date' => $o->date_operation, 'libelle' => 'Constat de solde'.($o->ecart ? ' (écart '.($o->ecart > 0 ? '+' : '−').gnf(abs($o->ecart)).')' : ''),
                'entree' => 0, 'sortie' => 0, 'constat' => $o->montant]);
        $solde = $soldeDebut;
        $lignes = $lignes->concat($constats)->sortBy(fn ($l) => $l['date']->timestamp)->values()->map(function ($l) use (&$solde) {
            $solde = isset($l['constat']) ? $l['constat'] : $solde + $l['entree'] - $l['sortie'];

            return $l + ['solde' => $solde];
        });

        return view('tresorerie.journal', [
            'compte' => $compte, 'libelle' => OperationTresorerie::libelleCompte($compte),
            'du' => $du, 'au' => $au, 'soldeDebut' => $soldeDebut, 'lignes' => $lignes, 'soldeFin' => $solde,
        ]);
    }
}
