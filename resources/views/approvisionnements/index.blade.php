@extends('layouts.app')
@section('titre', 'Approvisionnements')
@section('contenu')
    <div class="entete-page">
        <h1>Approvisionnements</h1>
        <a href="{{ route('approvisionnements.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouvelle réception</a>
    </div>
    <form class="bloc bloc-corps row g-2 align-items-end mb-3">
        <div class="col-md-4"><label class="form-label small text-doux mb-1" for="fournisseur_id">Fournisseur</label>
            <select name="fournisseur_id" id="fournisseur_id" class="form-select form-select-sm"><option value="">Tous</option>
                @foreach ($fournisseurs as $f)<option value="{{ $f->id }}" @selected(request('fournisseur_id') == $f->id)>{{ $f->nom }}</option>@endforeach</select></div>
        @include('partials.periode')
        <div class="col-md-auto"><button class="btn btn-sm btn-primary">Filtrer</button></div>
    </form>
    <div class="bloc">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>N°</th><th>Date</th><th>Fournisseur</th><th class="text-end">Produits</th><th class="text-end">Montant</th><th>Règlement</th><th>Saisi par</th></tr></thead>
                <tbody>
                @forelse ($approvisionnements as $a)
                    <tr><td><a href="{{ route('approvisionnements.show', $a) }}" class="fw-semibold">{{ $a->numero }}</a></td>
                        <td>{{ $a->date_appro->format('d/m/Y') }}</td><td>{{ $a->fournisseur?->nom ?? '—' }}</td>
                        <td class="text-end">{{ $a->lignes_count }}</td><td class="text-end montant">{{ gnf($a->total) }}</td>
                        <td>@if ($a->resteAPayer() === 0)<span class="etat etat-ok">Réglée</span>@else<span class="etat {{ $a->enRetard() ? 'etat-rupture' : 'etat-alerte' }}">Reste {{ gnf($a->resteAPayer()) }}</span>@endif</td>
                        <td class="text-doux">{{ $a->auteur?->nomComplet() }}</td></tr>
                @empty
                    <tr><td colspan="7" class="vide"><i class="bi bi-truck"></i>Aucune réception de marchandise enregistrée.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $approvisionnements->links() }}</div>
@endsection
