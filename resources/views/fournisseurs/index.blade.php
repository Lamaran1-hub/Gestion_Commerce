@extends('layouts.app')
@section('titre', 'Fournisseurs')
@section('contenu')
    <div class="entete-page"><h1>Fournisseurs</h1><a href="{{ route('fournisseurs.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouveau fournisseur</a></div>
    <form class="mb-3" style="max-width:420px"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Nom ou téléphone"></form>
    <div class="bloc">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Nom</th><th>Contact</th><th>Téléphone</th><th>Adresse</th><th class="text-end">Produits</th><th class="text-end">Total approvisionné</th><th></th></tr></thead>
                <tbody>
                @forelse ($fournisseurs as $f)
                    <tr><td class="fw-semibold">{{ $f->nom }}</td><td>{{ $f->contact }}</td><td class="text-nowrap">{{ numero_affiche($f->telephone) }}</td><td class="text-doux">{{ $f->adresse }}</td>
                        <td class="text-end">{{ $f->produits_count }}</td><td class="text-end montant">{{ gnf($f->total_appro ?? 0) }}</td>
                        <td class="text-end text-nowrap"><a href="{{ route('fournisseurs.edit', $f) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="{{ route('fournisseurs.destroy', $f) }}" class="d-inline" data-confirmer="Supprimer ce fournisseur ?">@csrf @method('delete')
                                <button class="btn btn-sm btn-light text-danger" aria-label="Supprimer"><i class="bi bi-trash"></i></button></form></td></tr>
                @empty
                    <tr><td colspan="7" class="vide"><i class="bi bi-building"></i>Aucun fournisseur.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $fournisseurs->links() }}</div>
@endsection
