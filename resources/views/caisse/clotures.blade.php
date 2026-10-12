@extends('layouts.app')
@section('titre', 'Clôtures de caisse')
@section('contenu')
    <div class="entete-page">
        <div><h1>Clôtures de caisse</h1><div class="text-doux">Rapports Z de fin de journée{{ $voitTout ? ', tous caissiers' : '' }}.</div></div>
        @can('ventes.creer')
            @if ($ouverteAujourdhui)
                <a href="{{ route('clotures.create') }}" class="btn btn-primary"><i class="bi bi-lock me-1"></i>Clôturer ma caisse</a>
            @else
                <span class="etat etat-ok"><i class="bi bi-check2-circle"></i>Ma caisse est clôturée aujourd'hui</span>
            @endif
        @endcan
    </div>
    <form class="bloc bloc-corps row g-2 align-items-end mb-3">
        @include('partials.periode')
        @if ($voitTout)
            <div class="col-md-3"><label class="form-label small text-doux mb-1" for="caissier">Caissier</label>
                <select name="caissier" id="caissier" class="form-select form-select-sm"><option value="">Tous</option>
                    @foreach ($caissiers as $u)<option value="{{ $u->id }}" @selected(request('caissier') == $u->id)>{{ $u->nomComplet() }}</option>@endforeach</select></div>
        @endif
        <div class="col-md-auto"><button class="btn btn-sm btn-primary">Filtrer</button></div>
    </form>
    @if ($parCaissier->isNotEmpty())
        {{-- Bilan par caissier sur la période : repérer les manques répétés --}}
        <div class="bloc mb-3" id="parCaissier">
            <div class="bloc-entete flex-wrap gap-1"><h2 class="mb-0"><i class="bi bi-person-check me-1"></i>Écarts par caissier</h2>
                <span class="small text-doux">« À surveiller » dès {{ $seuilManques }} manques sur la période</span></div>
            <div class="table-responsive"><table class="table mb-0 align-middle">
                <thead><tr><th>Caissier</th><th class="text-end d-none d-md-table-cell">Clôtures</th><th class="text-end">Manques</th><th class="text-end d-none d-md-table-cell">Excédents</th>
                    <th class="text-end">Net</th><th class="d-none d-md-table-cell">Autres signaux</th></tr></thead>
                <tbody>
                @foreach ($parCaissier as $l)
                    <tr class="{{ $l['a_surveiller'] ? 'table-warning' : '' }}">
                        <td><a href="{{ request()->fullUrlWithQuery(['caissier' => $l['caissier']?->id]) }}" class="fw-semibold">{{ $l['caissier']?->nomComplet() ?? 'Utilisateur supprimé' }}</a>
                            @if ($l['a_surveiller'])<span class="etat etat-rupture ms-1 text-nowrap">À surveiller</span>@endif
                            <div class="small text-doux d-md-none">{{ collect([$l['clotures'].' clôture(s)', $l['excedents'] ? $l['excedents'].' excédent(s)' : null, $l['fonds_differents'] ? $l['fonds_differents'].' fond(s) différent(s)' : null, $l['reouvertures'] ? $l['reouvertures'].' réouverture(s)' : null])->filter()->implode(' · ') }}</div></td>
                        <td class="text-end d-none d-md-table-cell">{{ $l['clotures'] }}</td>
                        <td class="text-end text-nowrap {{ $l['manques'] ? 'text-danger' : 'text-doux' }}">{{ $l['manques'] }}@if ($l['manques'])<div class="small">− {{ gnf($l['total_manques']) }}</div>@endif</td>
                        <td class="text-end text-nowrap d-none d-md-table-cell">{{ $l['excedents'] }}@if ($l['excedents'])<div class="small text-doux">+ {{ gnf($l['total_excedents']) }}</div>@endif</td>
                        <td class="text-end text-nowrap montant {{ $l['net'] < 0 ? 'text-danger' : '' }}">{{ $l['net'] === 0 ? '0' : ($l['net'] > 0 ? '+ ' : '− ').gnf(abs($l['net'])) }}</td>
                        <td class="small d-none d-md-table-cell">
                            @if ($l['fonds_differents'])<div class="text-danger">{{ $l['fonds_differents'] }} fond(s) différent(s) de la veille</div>@endif
                            @if ($l['reouvertures'])<div>{{ $l['reouvertures'] }} réouverture(s) de caisse</div>@endif
                            @unless ($l['fonds_differents'] || $l['reouvertures'])<span class="text-doux">—</span>@endunless</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    @endif
    <div class="bloc"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Jour</th><th>Caissier</th><th class="text-end">Ventes</th><th class="text-end">Encaissé</th><th class="text-end">Espèces attendues</th>
            <th class="text-end">Comptées</th><th>Écart</th><th></th></tr></thead>
        <tbody>
        @forelse ($clotures as $c)
            <tr><td>{{ $c->jour->format('d/m/Y') }}</td><td>{{ $c->caissier?->nomComplet() }}</td>
                <td class="text-end">{{ $c->nb_ventes }}</td><td class="text-end montant">{{ gnf($c->totalEncaisse()) }}</td>
                <td class="text-end montant">{{ gnf($c->especes_theoriques) }}</td><td class="text-end montant">{{ gnf($c->especes_comptees) }}</td>
                <td><span class="etat {{ $c->ecart === 0 ? 'etat-ok' : ($c->ecart > 0 ? 'etat-alerte' : 'etat-rupture') }}">{{ $c->libelleEcart() }}</span>
                    @if ($c->motif_ecart)<div class="small text-doux">{{ $c->motif_ecart }}</div>@endif</td>
                <td class="text-end text-nowrap"><a href="{{ route('clotures.show', $c) }}" class="btn btn-sm btn-light" title="Rapport Z"><i class="bi bi-receipt"></i></a>
                    @if ($c->jour->isToday() && auth()->user()->role?->systeme)
                        <form method="post" action="{{ route('clotures.destroy', $c) }}" class="d-inline"
                              data-confirmer="Rouvrir la caisse de {{ $c->caissier?->nomComplet() }} ? Le rapport Z du jour sera supprimé et devra être refait."
                              data-confirmer-titre="Rouvrir la caisse" data-confirmer-bouton="Oui, rouvrir" data-confirmer-type="alerte">@csrf @method('delete')
                            <button class="btn btn-sm btn-light" title="Rouvrir"><i class="bi bi-unlock"></i></button></form>
                    @endif</td></tr>
        @empty
            <tr><td colspan="8" class="vide"><i class="bi bi-lock"></i>Aucune clôture sur cette période.</td></tr>
        @endforelse
        </tbody>
        @if ($clotures->isNotEmpty())
            <tfoot><tr class="fw-semibold"><td colspan="6">Écart cumulé (page)</td>
                <td colspan="2" class="{{ $ecartTotal < 0 ? 'text-danger' : '' }}">{{ $ecartTotal === 0 ? 'Aucun' : ($ecartTotal > 0 ? '+ ' : '− ').gnf(abs($ecartTotal)) }}</td></tr></tfoot>
        @endif
    </table></div></div>
    <div class="mt-3">{{ $clotures->links() }}</div>
@endsection
