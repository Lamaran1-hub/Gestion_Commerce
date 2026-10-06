@extends('layouts.app')
@section('titre', 'Clients')
@section('contenu')
    <div class="entete-page"><div><h1>Clients (boutiques)</h1><div class="text-doux">Toutes les entreprises qui utilisent votre logiciel.</div></div>
        <a href="{{ route('admin.boutiques.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouveau client</a></div>
    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach (['' => 'Tous', 'actif' => 'Licence payée', 'essai' => 'En essai', 'bientot' => 'Échéance ≤ 7 jours', 'expire' => 'Expirés', 'suspendu' => 'Suspendus'] as $k => $l)
            <a href="{{ route('admin.boutiques.index', array_filter(['etat' => $k, 'q' => request('q')])) }}"
               class="btn btn-sm {{ request('etat', '') === $k ? 'btn-primary' : 'btn-outline-primary' }}">{{ $l }}</a>
        @endforeach
        <form class="ms-auto" style="min-width:260px"><input type="hidden" name="etat" value="{{ request('etat') }}">
            <input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Nom, responsable, téléphone, ville"></form>
    </div>
    <div class="bloc">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Client</th><th>Responsable</th><th>Formule</th><th>État</th><th>Échéance</th><th class="text-end">Utilisateurs</th></tr></thead>
                <tbody>
                @forelse ($boutiques as $b)
                    @php($j = $b->joursRestants())
                    <tr><td><a href="{{ route('admin.boutiques.show', $b) }}" class="fw-semibold">{{ $b->nom }}</a>
                            <div class="small text-doux">{{ collect([$b->secteur, $b->ville, $b->telephone])->filter()->implode(' · ') }}</div></td>
                        <td class="small">{{ $b->responsable_nom }}<div class="text-doux">{{ $b->responsable_telephone }}</div></td>
                        <td>{{ $b->plan?->nom ?? '—' }}</td>
                        <td><span class="etat {{ $b->licencePayee() ? 'etat-ok' : ($b->estActive() ? 'etat-alerte' : 'etat-rupture') }}">{{ $b->libelleStatut() }}</span></td>
                        <td>{{ $b->abonnement_expire_le?->format('d/m/Y') ?? 'Sans échéance' }}
                            @if ($j !== null && $j >= 0 && $j <= 7 && $b->statut !== 'suspendu')<div class="small text-warning-emphasis">dans {{ $j }} j</div>@endif</td>
                        <td class="text-end">{{ $b->utilisateurs_count }}</td></tr>
                @empty
                    <tr><td colspan="6" class="vide">Aucun client.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $boutiques->links() }}</div>
@endsection
