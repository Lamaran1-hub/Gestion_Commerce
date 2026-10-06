@extends('layouts.app')
@section('titre', 'Planning')
@section('contenu')
    @php
        $jours = collect(range(0, 6))->map(fn ($i) => $lundi->copy()->addDays($i));
        $noms = collect($employes)->keyBy('id');
    @endphp
    <div class="entete-page">
        <div><h1>{{ $gerant ? 'Planning de l\'équipe' : 'Mon planning' }}</h1>
            <div class="text-doux">Semaine du {{ $lundi->format('d/m/Y') }} au {{ $lundi->copy()->addDays(6)->format('d/m/Y') }}</div></div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('equipe.planning', ['semaine' => $lundi->copy()->subWeek()->toDateString()]) }}" class="btn btn-light" aria-label="Semaine précédente"><i class="bi bi-chevron-left"></i></a>
            <a href="{{ route('equipe.planning') }}" class="btn btn-light">Cette semaine</a>
            <a href="{{ route('equipe.planning', ['semaine' => $lundi->copy()->addWeek()->toDateString()]) }}" class="btn btn-light" aria-label="Semaine suivante"><i class="bi bi-chevron-right"></i></a>
            <a href="{{ route('equipe.index') }}" class="btn btn-outline-primary"><i class="bi bi-person-check me-1"></i>Pointages</a>
        </div>
    </div>

    <form method="post" action="{{ route('equipe.planning.store') }}" class="bloc mb-3" data-sans-confirmation>
        @csrf
        <input type="hidden" name="semaine" value="{{ $lundi->toDateString() }}">
        <div class="table-responsive"><table class="table mb-0 align-middle">
            <thead><tr><th>Employé</th>
                @foreach ($jours as $j)<th class="text-center {{ $j->isToday() ? 'table-success' : '' }}">{{ ucfirst($j->translatedFormat('D d/m')) }}</th>@endforeach
                <th class="text-end">Total</th></tr></thead>
            <tbody>
            @forelse ($employes as $e)
                <tr><td class="fw-semibold text-nowrap">{{ $e->nomComplet() }}</td>
                    @foreach ($jours as $j)
                        @php
                            $p = $grille[$e->id][$j->toDateString()] ?? null;
                        @endphp
                        <td class="text-center" style="min-width:120px">
                            @if ($gerant)
                                <input type="time" name="horaires[{{ $e->id }}][{{ $j->toDateString() }}][debut]" value="{{ $p ? substr($p->debut, 0, 5) : '' }}"
                                       class="form-control form-control-sm mb-1" aria-label="Début {{ $e->prenom }} {{ $j->format('d/m') }}">
                                <input type="time" name="horaires[{{ $e->id }}][{{ $j->toDateString() }}][fin]" value="{{ $p ? substr($p->fin, 0, 5) : '' }}"
                                       class="form-control form-control-sm" aria-label="Fin {{ $e->prenom }} {{ $j->format('d/m') }}">
                            @else
                                {{ $p ? substr($p->debut, 0, 5).' – '.substr($p->fin, 0, 5) : 'Repos' }}
                            @endif
                        </td>
                    @endforeach
                    <td class="text-end text-nowrap">{{ \App\Models\Pointage::duree(collect($grille[$e->id] ?? [])->sum(fn ($p) => $p->minutes())) }}</td></tr>
            @empty
                <tr><td colspan="9" class="vide">Aucun employé actif.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @if ($gerant)
            <div class="bloc-corps border-top d-flex flex-wrap gap-2 align-items-center">
                <button class="btn btn-primary">Enregistrer le planning</button>
                <button class="btn btn-outline-primary" formaction="{{ route('equipe.planning.copier', ['semaine' => $lundi->toDateString()]) }}" formnovalidate>
                    <i class="bi bi-copy me-1"></i>Recopier la semaine précédente</button>
                <span class="small text-doux">Laissez les deux heures vides pour un jour de repos.</span>
            </div>
        @endif
    </form>

    @if ($gerant && $couverture)
        <div class="bloc mb-3">
            <div class="bloc-entete flex-wrap gap-2"><h2 class="mb-0"><i class="bi bi-people me-1"></i>Couverture et affluence</h2>
                <span class="small text-doux">Personnes prévues par heure ; la couleur montre l'affluence habituelle (4 dernières semaines).
                    <span class="etat etat-rupture">!</span> = renfort conseillé</span></div>
            <div class="table-responsive"><table class="table table-sm table-bordered mb-0 text-center small">
                <thead><tr><th>Heure</th>@foreach ($jours as $j)<th>{{ ucfirst($j->translatedFormat('D')) }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach (range(\App\Services\PlanningEquipe::HEURES[0], \App\Services\PlanningEquipe::HEURES[1] - 1) as $h)
                    <tr><th class="fw-normal text-doux">{{ $h }}h</th>
                        @foreach ($jours as $j)
                            @php
                                $c = $couverture[$j->toDateString()][$h];
                            @endphp
                            <td style="background: rgba(31,111,84,{{ min(0.6, $c['part'] / 25) }})" title="{{ $c['part'] }} % du chiffre d'affaires habituel">
                                {{ $c['presents'] ?: '·' }}@if ($c['renfort'])<span class="etat etat-rupture ms-1" title="Renfort conseillé">!</span>@endif</td>
                        @endforeach</tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    @endif

    <div class="bloc">
        <div class="bloc-entete"><h2 class="mb-0">Retards et absences de la semaine</h2>
            <span class="small text-doux">Retard : arrivée plus de {{ \App\Services\PlanningEquipe::TOLERANCE_RETARD_MINUTES }} min après l'heure prévue</span></div>
        <table class="table table-sm mb-0"><tbody>
            @forelse ($ecarts as $e)
                <tr><td>{{ $noms[$e['user_id']]?->nomComplet() ?? auth()->user()->nomComplet() }}</td><td>{{ \Carbon\Carbon::parse($e['jour'])->translatedFormat('l d/m') }}</td>
                    <td>@if ($e['type'] === 'retard')<span class="etat etat-alerte">Retard de {{ $e['minutes'] }} min</span>@else<span class="etat etat-rupture">Absent (non pointé)</span>@endif</td></tr>
            @empty
                <tr><td class="vide">Aucun retard ni absence.</td></tr>
            @endforelse
        </tbody></table>
    </div>
@endsection
