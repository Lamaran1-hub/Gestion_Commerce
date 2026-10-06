@extends('layouts.app')
@section('titre', 'Catégories')
@section('contenu')
    <div class="entete-page"><h1>Catégories de produits</h1><a href="{{ route('produits.index') }}" class="btn btn-light">Retour aux produits</a></div>
    <div class="row g-4">
        <div class="col-lg-4">
            <form method="post" action="{{ route('categories.store') }}" class="bloc bloc-corps">
                @csrf
                <label class="form-label" for="nom">Nouvelle catégorie</label>
                <div class="input-group"><input name="nom" id="nom" class="form-control" required placeholder="Ex. : Boissons"><button class="btn btn-primary">Ajouter</button></div>
            </form>
        </div>
        <div class="col-lg-8">
            <div class="bloc">
                <table class="table">
                    <thead><tr><th>Nom</th><th class="text-end">Produits</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($categories as $c)
                        <tr>
                            <td><form method="post" action="{{ route('categories.update', $c) }}" class="d-flex gap-2">@csrf @method('put')
                                <input name="nom" value="{{ $c->nom }}" class="form-control form-control-sm" aria-label="Nom"><button class="btn btn-sm btn-light">Renommer</button></form></td>
                            <td class="text-end">{{ $c->produits_count }}</td>
                            <td class="text-end"><form method="post" action="{{ route('categories.destroy', $c) }}" data-confirmer="Supprimer cette catégorie ? Ses produits resteront, sans catégorie.">@csrf @method('delete')
                                <button class="btn btn-sm btn-link text-danger">Supprimer</button></form></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="vide">Aucune catégorie pour l'instant.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
