<?php

namespace App\Http\Controllers;

use App\Exceptions\OperationRefusee;
use App\Models\JournalActivite;
use App\Models\Pointage;
use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Gestion d'équipe : chacun pointe son arrivée et son départ ; le gérant voit les présences,
 * les heures travaillées et les ventes de chacun, et corrige un oubli (avec motif, tracé).
 */
class PointageController extends Controller
{
    /** Pointage de l'utilisateur connecté : arrivée si rien d'ouvert, sinon départ. */
    public function pointer(Request $request)
    {
        $user = $request->user();
        $ouvert = Pointage::where('user_id', $user->id)->whereNull('depart')->latest('arrivee')->first();
        if ($ouvert && $ouvert->oubli()) {
            throw new OperationRefusee('Votre départ du '.$ouvert->arrivee->format('d/m/Y').' n\'a pas été pointé : demandez au responsable de le corriger (menu Équipe).');
        }
        if ($ouvert) {
            $ouvert->update(['depart' => now()]);
            JournalActivite::noter('equipe', $user->nomComplet().' a pointé son départ ('.Pointage::duree($ouvert->minutes()).')');

            return back()->with('succes', 'Départ pointé à '.now()->format('H:i').'. Temps de travail : '.Pointage::duree($ouvert->minutes()).'.');
        }
        Pointage::create(['user_id' => $user->id, 'arrivee' => now()]);
        JournalActivite::noter('equipe', $user->nomComplet().' a pointé son arrivée');

        return back()->with('succes', 'Arrivée pointée à '.now()->format('H:i').'. Bonne journée !');
    }

    public function index(Request $request)
    {
        $gerant = $request->user()->aPermission('utilisateurs.gerer');
        $du = ($request->date('du') ?? now()->startOfMonth())->copy()->startOfDay();
        $au = ($request->date('au') ?? now())->copy()->endOfDay();
        $pointages = Pointage::with('employe')->whereBetween('arrivee', [$du, $au])
            ->when(! $gerant, fn ($q) => $q->where('user_id', $request->user()->id))
            ->latest('arrivee')->get();

        // Par employé : jours, heures, ventes, chiffre d'affaires par heure travaillée
        $ventes = Vente::validees()->whereBetween('date_vente', [$du, $au])->selectRaw('user_id, COUNT(*) as nb, SUM(total_ttc) as ca')
            ->groupBy('user_id')->get()->keyBy('user_id');
        $synthese = $pointages->groupBy('user_id')->map(function ($liste, $id) use ($ventes) {
            $minutes = $liste->sum(fn (Pointage $p) => $p->minutes());
            $v = $ventes->get($id);

            return ['employe' => $liste->first()->employe, 'jours' => $liste->groupBy(fn ($p) => $p->arrivee->toDateString())->count(),
                'minutes' => $minutes, 'ventes' => (int) ($v->nb ?? 0), 'ca' => (int) ($v->ca ?? 0),
                'ca_heure' => $minutes > 0 ? (int) round(($v->ca ?? 0) * 60 / $minutes) : 0,
                'oublis' => $liste->filter->oubli()->count()];
        })->sortByDesc('minutes')->values();

        return view('equipe.index', [
            'du' => $du, 'au' => $au, 'gerant' => $gerant, 'pointages' => $pointages, 'synthese' => $synthese,
            'presents' => $gerant ? Pointage::with('employe')->whereNull('depart')->whereDate('arrivee', now()->toDateString())->get() : collect(),
            'employes' => $gerant ? User::where('boutique_id', boutique()->id)->where('actif', true)->orderBy('prenom')->get() : collect(),
        ]);
    }

    /** Correction par le responsable (oubli de pointage) : motif obligatoire, tracé dans le journal. */
    public function corriger(Request $request, Pointage $pointage)
    {
        $d = $request->validate([
            'arrivee' => ['required', 'date', 'before_or_equal:now'],
            'depart' => ['nullable', 'date', 'after:arrivee', 'before_or_equal:now'],
            'note' => ['required', 'string', 'max:200'],
        ], ['note.required' => 'Indiquez le motif de la correction.', 'depart.after' => "Le départ doit être après l'arrivée."]);
        $avant = $pointage->arrivee->format('d/m H:i').' → '.($pointage->depart?->format('d/m H:i') ?? '…');
        $pointage->update($d + ['corrige_par' => $request->user()->id]);
        JournalActivite::noter('equipe', "Pointage de {$pointage->employe?->nomComplet()} corrigé ({$avant} devient "
            .$pointage->arrivee->format('d/m H:i').' → '.($pointage->depart?->format('d/m H:i') ?? '…').") : {$d['note']}");

        return back()->with('succes', 'Pointage corrigé.');
    }

    /** Pointage ajouté par le responsable pour un employé qui a oublié de pointer. */
    public function store(Request $request)
    {
        $d = $request->validate([
            'user_id' => ['required', \Illuminate\Validation\Rule::exists('users', 'id')->where('boutique_id', boutique()->id)],
            'arrivee' => ['required', 'date', 'before_or_equal:now'],
            'depart' => ['required', 'date', 'after:arrivee', 'before_or_equal:now'],
            'note' => ['required', 'string', 'max:200'],
        ], ['note.required' => 'Indiquez le motif (oubli de pointage…).']);
        $p = Pointage::create($d + ['corrige_par' => $request->user()->id]);
        JournalActivite::noter('equipe', "Pointage ajouté pour {$p->employe?->nomComplet()} (".Carbon::parse($d['arrivee'])->format('d/m H:i').' → '
            .Carbon::parse($d['depart'])->format('d/m H:i').") : {$d['note']}");

        return back()->with('succes', 'Pointage ajouté.');
    }
}
