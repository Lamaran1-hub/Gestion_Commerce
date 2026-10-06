@extends('layouts.app')
@section('titre', 'Crédits clients')
@section('contenu')
    <div class="entete-page">
        <div><h1>Crédits clients</h1><div class="text-doux">Total à encaisser : <strong class="montant text-danger">{{ gnf($total) }}</strong>
            @if ($totalRetard) · dont <strong class="montant text-danger">{{ gnf($totalRetard) }}</strong> en retard @endif</div></div>
        @can('rapports.voir')
            <a href="{{ route('rapports.export', ['credits', 'excel']) }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Exporter</a>
        @endcan
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Filtrer les crédits">
            <a href="{{ route('credits.index', array_filter(['q' => request('q')])) }}" class="btn {{ ! $vue ? 'btn-primary' : 'btn-outline-primary' }}">Tous</a>
            <a href="{{ route('credits.index', array_filter(['vue' => 'retard', 'q' => request('q')])) }}" class="btn {{ $vue === 'retard' ? 'btn-danger' : 'btn-outline-danger' }}">En retard @if ($compter['retard'])<span class="badge text-bg-light ms-1">{{ $compter['retard'] }}</span>@endif</a>
            <a href="{{ route('credits.index', array_filter(['vue' => 'semaine', 'q' => request('q')])) }}" class="btn {{ $vue === 'semaine' ? 'btn-warning' : 'btn-outline-warning' }}">Échéance dans 7 jours @if ($compter['semaine'])<span class="badge text-bg-light ms-1">{{ $compter['semaine'] }}</span>@endif</a>
        </div>
        <form class="flex-grow-1" style="max-width:420px">@if ($vue)<input type="hidden" name="vue" value="{{ $vue }}">@endif
            <input name="q" value="{{ request('q') }}" class="form-control" placeholder="Rechercher un client (nom, téléphone)" aria-label="Rechercher un client"></form>
    </div>
    <div class="bloc">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Client</th><th class="d-none d-md-table-cell">Téléphone</th><th class="text-end d-none d-lg-table-cell">Ventes</th><th>À payer avant le</th><th class="text-end d-none d-md-table-cell">Reste à payer</th><th class="no-print d-none d-md-table-cell">Encaisser un versement</th></tr></thead>
                <tbody>
                @forelse ($soldes as $s)
                    @php($c = $clients[$s->client_id])
                    <tr>
                        <td><a href="{{ route('clients.show', $c) }}" class="fw-semibold">{{ $c->nomComplet() }}</a>
                            {{-- Sur téléphone : numéro sous le nom, pour garder l'échéance et le montant à l'écran --}}
                            @if ($c->telephone)<div class="small text-doux text-nowrap d-md-none">{{ numero_affiche($c->telephone) }}</div>@endif</td>
                        <td class="text-nowrap d-none d-md-table-cell">{{ numero_affiche($c->telephone) }}
                            @if ($c->derniere_relance_le)<div class="small text-doux">relancé {{ $c->derniere_relance_le->diffForHumans() }}</div>@endif</td>
                        <td class="text-end d-none d-lg-table-cell">{{ $s->nb }}</td>
                        {{-- Échéance promise la plus proche ; en retard dès qu'elle est passée --}}
                        @php($ech = $s->prochaine_echeance ? \Carbon\Carbon::parse($s->prochaine_echeance) : null)
                        <td>{{ $ech?->format('d/m/Y') ?? '—' }}
                            @if ($s->en_retard > 0)<div><span class="etat etat-rupture">en retard de {{ (int) $ech->diffInDays(now()->startOfDay()) }} j</span></div>
                                @if ($s->en_retard < $s->du)<div class="small text-danger">{{ gnf($s->en_retard) }} en retard</div>@endif
                            @elseif ($ech && $ech->lte(now()->addDays(7)))<div><span class="etat etat-alerte">{{ $ech->isToday() ? "aujourd'hui" : 'dans '.(int) now()->startOfDay()->diffInDays($ech).' j' }}</span></div>
                            @endif
                            <div class="small text-doux">depuis le {{ \Carbon\Carbon::parse($s->plus_ancienne)->format('d/m/Y') }}</div>
                            {{-- Sur téléphone : montant dû et accès à la fiche (encaisser, relancer) sous l'échéance --}}
                            <div class="d-md-none mt-1 d-flex align-items-center gap-2 flex-wrap"><strong class="montant">{{ gnf($s->du) }}</strong>
                                <a href="{{ route('clients.show', $c) }}" class="btn btn-sm btn-outline-primary py-0">Encaisser</a></div></td>
                        <td class="text-end fw-bold montant text-nowrap d-none d-md-table-cell">{{ gnf($s->du) }}</td>
                        <td class="no-print d-none d-md-table-cell">
                            <div class="d-flex gap-1 mb-1">
                                <a href="{{ route('clients.releve', $c) }}" target="_blank" class="btn btn-sm btn-light" title="Relevé de compte"><i class="bi bi-file-earmark-text"></i> Relevé</a>
                                @if ($c->telephone && fonction('relances'))
                                    <form method="post" action="{{ route('clients.relancer', $c) }}" target="_blank" data-sans-confirmation>@csrf
                                        <button class="btn btn-sm btn-success"><i class="bi bi-whatsapp"></i> Relancer</button></form>
                                @endif
                            </div>
                            @can('paiements.creer')
                                <form method="post" action="{{ route('credits.store', $c) }}" class="d-flex flex-wrap gap-1"
                                      data-confirmer="Enregistrer ce versement de {{ $c->nomComplet() }} ?" data-confirmer-titre="Confirmer l'encaissement" data-confirmer-bouton="Oui, encaisser">
                                    @csrf
                                    <input name="montant" data-montant class="form-control form-control-sm" style="width:130px" placeholder="Montant" aria-label="Montant" required>
                                    <select name="mode" class="form-select form-select-sm" style="width:140px" aria-label="Mode" data-autre="autre" data-autre-placeholder="Précisez le mode">
                                        @foreach (config('gestion.modes_paiement') as $cle => $lib)<option value="{{ $cle }}">{{ $lib }}</option>@endforeach
                                    </select>
                                    <button class="btn btn-sm btn-primary">OK</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="vide"><i class="bi bi-emoji-smile"></i>{{ $vue === 'retard' ? 'Aucun crédit en retard.' : ($vue === 'semaine' ? 'Aucune échéance dans les 7 prochains jours.' : "Aucun client ne doit d'argent à la boutique.") }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-doux mt-2">Un versement est affecté automatiquement aux ventes les plus anciennes du client.</p>
@endsection
