@extends('layouts.app')
@section('titre', 'Transferts de stock')
@section('contenu')
    <div class="entete-page">
        <div><h1>Transferts de stock</h1><div class="text-doux">Marchandise envoyée et reçue entre les boutiques de votre réseau.</div></div>
        @if ($autres->isNotEmpty())<a href="{{ route('transferts.create') }}" class="btn btn-primary"><i class="bi bi-send me-1"></i>Nouveau transfert</a>@endif
    </div>
    @if ($autres->isEmpty())
        <div class="alert alert-info">Votre réseau ne compte qu'une boutique. L'administrateur peut ajouter un point de vente depuis <strong>Mes boutiques</strong>.</div>
    @endif

    @if ($aRecevoir->isNotEmpty())
        <div class="bloc mb-3 border-warning">
            <div class="bloc-entete"><h2 class="mb-0 text-warning-emphasis"><i class="bi bi-truck me-1"></i>À réceptionner ({{ $aRecevoir->count() }})</h2></div>
            <table class="table mb-0 align-middle"><tbody>
                @foreach ($aRecevoir as $t)
                    <tr><td class="fw-semibold">{{ $t->numero }}</td><td>depuis {{ $t->source->nom }}</td>
                        <td class="small text-doux">envoyé {{ $t->envoye_le->diffForHumans() }} par {{ $t->expediteur?->nomComplet() }}</td>
                        <td class="text-end"><a href="{{ route('transferts.show', $t) }}" class="btn btn-sm btn-warning">Réceptionner</a></td></tr>
                @endforeach
            </tbody></table>
        </div>
    @endif

    <div class="bloc"><div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>N°</th><th>Date</th><th>Sens</th><th class="text-end">Valeur</th><th>État</th></tr></thead>
            <tbody>
            @forelse ($transferts as $t)
                @php($envoi = $t->boutique_source_id === boutique()->id)
                <tr><td><a href="{{ route('transferts.show', $t) }}" class="fw-semibold">{{ $t->numero }}</a></td>
                    <td class="small">{{ $t->envoye_le?->format('d/m/Y H:i') }}</td>
                    <td>@if ($envoi)<i class="bi bi-box-arrow-up-right text-danger"></i> vers {{ $t->destination->nom }}@else<i class="bi bi-box-arrow-in-down-left text-success"></i> depuis {{ $t->source->nom }}@endif</td>
                    <td class="text-end montant">{{ gnf($t->valeur) }}</td>
                    <td><span class="etat {{ ['envoye' => 'etat-alerte', 'recu' => 'etat-ok'][$t->statut] ?? 'etat-rupture' }}">{{ $t->libelleStatut() }}</span></td></tr>
            @empty
                <tr><td colspan="5" class="vide">Aucun transfert pour l'instant.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div></div>
    <div class="mt-2">{{ $transferts->links() }}</div>
@endsection
