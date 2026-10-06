@extends('layouts.app')
@section('titre', 'Équipe')
@section('contenu')
    <div class="entete-page">
        <div><h1>{{ $gerant ? 'Équipe et présences' : 'Mes pointages' }}</h1>
            <div class="text-doux">Du {{ $du->format('d/m/Y') }} au {{ $au->format('d/m/Y') }} · heures travaillées rapprochées des ventes réalisées.</div></div>
        <a href="{{ route('equipe.planning') }}" class="btn btn-primary"><i class="bi bi-calendar-week me-1"></i>{{ $gerant ? 'Planning de l\'équipe' : 'Mon planning' }}</a>
    </div>
    <form class="bloc bloc-corps row g-2 align-items-end mb-3">
        @include('partials.periode', ['du' => $du->toDateString(), 'au' => $au->toDateString()])
        <div class="col-md-auto"><button class="btn btn-sm btn-primary">Afficher</button></div>
    </form>

    @if ($gerant)
        <div class="bloc mb-3">
            <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-person-check me-1"></i>Présents maintenant ({{ $presents->count() }})</h2></div>
            <div class="bloc-corps d-flex flex-wrap gap-2">
                @forelse ($presents as $p)
                    <span class="etat etat-ok">{{ $p->employe?->nomComplet() }} · depuis {{ $p->arrivee->format('H:i') }}</span>
                @empty
                    <span class="text-doux">Personne n'a pointé son arrivée pour l'instant.</span>
                @endforelse
            </div>
        </div>
    @endif

    <div class="bloc mb-3"><div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead><tr><th>Employé</th><th class="text-end">Jours</th><th class="text-end">Heures</th><th class="text-end">Ventes</th>
                <th class="text-end">Chiffre d'affaires</th><th class="text-end">CA par heure</th><th></th></tr></thead>
            <tbody>
            @forelse ($synthese as $s)
                <tr><td class="fw-semibold">{{ $s['employe']?->nomComplet() }}</td><td class="text-end">{{ $s['jours'] }}</td>
                    <td class="text-end">{{ \App\Models\Pointage::duree($s['minutes']) }}</td><td class="text-end">{{ $s['ventes'] }}</td>
                    <td class="text-end montant">{{ gnf($s['ca']) }}</td><td class="text-end montant">{{ gnf($s['ca_heure']) }}</td>
                    <td>@if ($s['oublis'])<span class="etat etat-rupture">{{ $s['oublis'] }} départ(s) non pointé(s)</span>@endif</td></tr>
            @empty
                <tr><td colspan="7" class="vide">Aucun pointage sur la période. Chacun pointe avec le bouton « Pointer mon arrivée » en haut de l'écran.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div></div>

    <div class="row g-3">
        <div class="{{ $gerant ? 'col-lg-8' : 'col-12' }}">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Détail des pointages</h2></div>
                <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <thead><tr>@if ($gerant)<th>Employé</th>@endif<th>Date</th><th>Arrivée</th><th>Départ</th><th class="text-end">Durée</th>@if ($gerant)<th>Corriger</th>@endif</tr></thead>
                    <tbody>
                    @forelse ($pointages as $p)
                        <tr class="{{ $p->oubli() ? 'table-warning' : '' }}">
                            @if ($gerant)<td>{{ $p->employe?->nomComplet() }}</td>@endif
                            <td>{{ $p->arrivee->format('d/m/Y') }}</td><td>{{ $p->arrivee->format('H:i') }}</td>
                            <td>{{ $p->depart?->format('H:i') ?? ($p->oubli() ? 'non pointé' : 'en cours') }}
                                @if ($p->note)<div class="small text-doux">corrigé : {{ $p->note }}</div>@endif</td>
                            <td class="text-end">{{ \App\Models\Pointage::duree($p->minutes()) }}</td>
                            @if ($gerant)
                                <td>
                                    <form method="post" action="{{ route('equipe.corriger', $p) }}" class="d-flex flex-wrap gap-1"
                                          data-confirmer="Corriger ce pointage ? La correction est enregistrée dans le journal." data-confirmer-titre="Correction" data-confirmer-bouton="Oui, corriger">
                                        @csrf @method('put')
                                        <input type="datetime-local" name="arrivee" value="{{ $p->arrivee->format('Y-m-d\TH:i') }}" class="form-control form-control-sm" style="width:auto" aria-label="Arrivée">
                                        <input type="datetime-local" name="depart" value="{{ $p->depart?->format('Y-m-d\TH:i') }}" class="form-control form-control-sm" style="width:auto" aria-label="Départ">
                                        <input name="note" class="form-control form-control-sm" style="width:140px" placeholder="Motif" required aria-label="Motif de la correction">
                                        <button class="btn btn-sm btn-light">OK</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6" class="vide">Aucun pointage.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>
        </div>
        @if ($gerant)
            <div class="col-lg-4">
                <form method="post" action="{{ route('equipe.store') }}" class="bloc bloc-corps"
                      data-confirmer="Ajouter ce pointage ? Il est enregistré dans le journal." data-confirmer-titre="Ajout de pointage" data-confirmer-bouton="Oui, ajouter">
                    @csrf
                    <h2 class="h6">Ajouter un pointage oublié</h2>
                    <select name="user_id" class="form-select form-select-sm mb-2" required aria-label="Employé">
                        @foreach ($employes as $e)<option value="{{ $e->id }}">{{ $e->nomComplet() }}</option>@endforeach
                    </select>
                    <label class="form-label small mb-0" for="aj_arrivee">Arrivée</label>
                    <input type="datetime-local" name="arrivee" id="aj_arrivee" class="form-control form-control-sm mb-2" required>
                    <label class="form-label small mb-0" for="aj_depart">Départ</label>
                    <input type="datetime-local" name="depart" id="aj_depart" class="form-control form-control-sm mb-2" required>
                    <input name="note" class="form-control form-control-sm mb-2" placeholder="Motif (obligatoire)" required aria-label="Motif">
                    <button class="btn btn-sm btn-primary w-100">Ajouter</button>
                </form>
            </div>
        @endif
    </div>
@endsection
