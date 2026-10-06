@extends('layouts.app')
@section('titre', 'Fiches clients en double')
@section('contenu')
    <div class="entete-page">
        <div><a href="{{ route('clients.index') }}" class="small"><i class="bi bi-arrow-left"></i> Clients</a>
            <h1>Fiches clients en double</h1>
            <div class="text-doux">Même nom ou même téléphone : souvent le même client, recréé à la caisse. Fusionnez pour regrouper ses achats, ses crédits, ses points et ses avoirs.</div></div>
    </div>
    @forelse ($groupes as $i => $groupe)
        @php($plusAncienne = $groupe->sortBy('id')->first())
        <form method="post" action="{{ route('clients.fusionner') }}" class="bloc mb-3"
              data-confirmer="Fusionner les fiches cochées dans la fiche conservée ? Achats, crédits, points et avoirs seront regroupés ; les fiches cochées seront supprimées."
              data-confirmer-titre="Fusionner des fiches" data-confirmer-bouton="Oui, fusionner">
            @csrf
            <div class="table-responsive"><table class="table mb-0 align-middle">
                <thead><tr><th>Garder</th><th>Fusionner</th><th>Client</th><th class="text-end d-none d-sm-table-cell">Achats</th><th class="text-end">Doit</th></tr></thead>
                <tbody>
                @foreach ($groupe as $c)
                    @php($a = $achats[$c->id] ?? null)
                    <tr>
                        <td><input type="radio" name="garde_id" value="{{ $c->id }}" class="form-check-input" aria-label="Garder la fiche {{ $c->code }}" @checked($c->id === $plusAncienne->id) required></td>
                        <td><input type="checkbox" name="doublons[]" value="{{ $c->id }}" class="form-check-input" aria-label="Fusionner la fiche {{ $c->code }}" @checked($c->id !== $plusAncienne->id)></td>
                        <td><a href="{{ route('clients.show', $c) }}" class="fw-semibold">{{ $c->nomComplet() }}</a>
                            <div class="small text-doux">{{ $c->code }}{{ $c->telephone ? ' · '.numero_affiche($c->telephone) : '' }} · créée le {{ $c->created_at?->format('d/m/Y') }}</div>
                            @if ($c->points || $c->avoir)<div class="small">{{ $c->points ? number_format($c->points, 0, ',', ' ').' points' : '' }}{{ $c->points && $c->avoir ? ' · ' : '' }}{{ $c->avoir ? 'avoir '.gnf($c->avoir) : '' }}</div>@endif
                            <div class="small text-doux d-sm-none">{{ $a->nb ?? 0 }} achat(s)</div></td>
                        <td class="text-end d-none d-sm-table-cell">{{ $a->nb ?? 0 }}@if ($a?->derniere)<div class="small text-doux">dernier {{ \Carbon\Carbon::parse($a->derniere)->format('d/m/Y') }}</div>@endif</td>
                        <td class="text-end montant text-nowrap {{ ($dettes[$c->id] ?? 0) > 0 ? 'text-danger' : 'text-doux' }}">{{ gnf((int) ($dettes[$c->id] ?? 0)) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="bloc-corps d-flex flex-wrap justify-content-between align-items-center gap-2 border-top">
                <span class="small text-doux">Par défaut, la fiche la plus ancienne est conservée. Vérifiez qu'il s'agit bien de la même personne.</span>
                <button class="btn btn-primary"><i class="bi bi-intersect me-1"></i>Fusionner</button>
            </div>
        </form>
    @empty
        <div class="bloc"><p class="vide mb-0"><i class="bi bi-emoji-smile"></i>Aucune fiche en double repérée : chaque client a sa fiche.</p></div>
    @endforelse
@endsection
