@extends('layouts.app')
@section('titre', 'Importer des produits')
@section('contenu')
    <div class="entete-page">
        <div><h1>Importer des produits</h1>
            <div class="text-doux">Ajoutez ou mettez à jour tout votre catalogue en une fois depuis Excel.</div></div>
        <a href="{{ route('produits.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Produits</a>
    </div>

    @isset($analyse)
        @php($compte = $analyse->countBy('action'))
        <div class="bloc mb-3">
            <div class="bloc-entete flex-wrap">
                <h2 class="mb-0">Aperçu de « {{ $nomFichier }} »</h2>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="etat etat-ok">{{ $compte['creer'] ?? 0 }} à créer</span>
                    <span class="etat etat-alerte">{{ $compte['maj'] ?? 0 }} à mettre à jour</span>
                    @if ($compte['erreur'] ?? 0)<span class="etat etat-rupture">{{ $compte['erreur'] }} en erreur (ignorées)</span>@endif
                </div>
            </div>
            <div class="table-responsive" style="max-height:60vh"><table class="table table-sm mb-0 align-middle">
                <thead class="sticky-top bg-white"><tr><th>Ligne</th><th>Action</th><th>Désignation</th><th>Code-barres</th><th>Catégorie</th>
                    <th class="text-end">Prix d'achat</th><th class="text-end">Prix de vente</th><th class="text-end">Stock</th></tr></thead>
                <tbody>
                @foreach ($analyse as $r)
                    @php($d = $r['donnees'])
                    <tr class="{{ $r['action'] === 'erreur' ? 'table-danger' : '' }}">
                        <td>{{ $r['ligne'] }}</td>
                        <td>@switch($r['action'])
                                @case('creer')<span class="etat etat-ok">Nouveau</span>@break
                                @case('maj')<span class="etat etat-alerte">Mise à jour</span>@break
                                @default<span class="etat etat-rupture">Erreur</span>
                            @endswitch</td>
                        <td>{{ $d['designation'] ?: '—' }}
                            @foreach ($r['erreurs'] as $e)<div class="small text-danger">{{ $e }}</div>@endforeach</td>
                        <td>{{ $d['code_barre'] }}</td><td>{{ $d['categorie'] }}</td>
                        <td class="text-end">{{ gnf($d['prix_achat']) }}</td><td class="text-end">{{ $d['prix_vente'] ? gnf($d['prix_vente']) : '—' }}</td>
                        <td class="text-end">@if ($r['action'] === 'maj')<span class="text-doux small" title="Le stock d'un produit existant se corrige par l'inventaire">inchangé</span>@else{{ qte($d['stock']) }}@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
        @if (($compte['creer'] ?? 0) + ($compte['maj'] ?? 0))
            <form method="post" action="{{ route('produits.import.store') }}" class="d-flex gap-2 mb-4"
                  data-confirmer="Importer {{ ($compte['creer'] ?? 0) + ($compte['maj'] ?? 0) }} produit(s) ?" data-confirmer-titre="Confirmer l'import" data-confirmer-bouton="Oui, importer">
                @csrf
                <button class="btn btn-primary btn-lg"><i class="bi bi-check2 me-1"></i>Importer {{ ($compte['creer'] ?? 0) + ($compte['maj'] ?? 0) }} produit(s)</button>
                <a href="{{ route('produits.import') }}" class="btn btn-light btn-lg">Choisir un autre fichier</a>
            </form>
        @endif
    @endisset

    <div class="row g-3">
        <div class="col-lg-6">
            <form method="post" action="{{ route('produits.import.apercu') }}" enctype="multipart/form-data" class="bloc bloc-corps" data-sans-confirmation>
                @csrf
                <h2 class="h6"><span class="badge bg-primary me-1">1</span>Téléchargez le modèle</h2>
                <p class="small text-doux">Remplissez une ligne par produit. Seules la <strong>désignation</strong> et le <strong>prix de vente</strong> sont obligatoires.</p>
                <a href="{{ route('produits.import.modele') }}" class="btn btn-outline-primary mb-4"><i class="bi bi-file-earmark-excel me-1"></i>Modèle Excel</a>
                <h2 class="h6"><span class="badge bg-primary me-1">2</span>Chargez votre fichier</h2>
                <input type="file" name="fichier" accept=".xlsx,.xls,.csv" class="form-control mb-2" required aria-label="Fichier Excel ou CSV">
                <button class="btn btn-primary"><i class="bi bi-eye me-1"></i>Vérifier avant d'importer</button>
                <div class="form-text">Rien n'est enregistré avant votre confirmation.</div>
            </form>
        </div>
        <div class="col-lg-6">
            <div class="bloc bloc-corps small">
                <h2 class="h6">Comment ça marche</h2>
                <ul class="mb-0 ps-3">
                    <li>Un produit déjà présent (même code-barres, ou même désignation) est <strong>mis à jour</strong> : prix, catégorie, seuil.</li>
                    <li>Son <strong>stock n'est pas modifié</strong> : corrigez-le par l'inventaire, pour garder la trace des écarts.</li>
                    <li>Pour un nouveau produit, la colonne Stock devient son stock initial.</li>
                    <li>Catégories et fournisseurs inconnus sont créés automatiquement.</li>
                    <li>Les montants peuvent être écrits « 250000 » ou « 250 000 ».</li>
                    <li>Vous pouvez aussi réimporter l'export Excel de vos produits après l'avoir modifié.</li>
                </ul>
            </div>
        </div>
    </div>
@endsection
