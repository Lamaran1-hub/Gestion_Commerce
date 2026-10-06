@extends('layouts.app')
@section('titre', 'À commander')
@section('contenu')
    <div class="entete-page">
        <div><h1>Quoi commander ?</h1>
            <div class="text-doux">Calculé sur les ventes des 30 derniers jours, pour tenir {{ $couverture }} jours (réglable dans Paramètres).
                @if ($totalCout) Budget estimé : <strong class="montant">{{ gnf($totalCout) }}</strong>.@endif</div></div>
        @if ($nbRuptures)<span class="etat etat-rupture">{{ $nbRuptures }} produit(s) en rupture</span>@endif
    </div>
    @php($enAttente = \App\Models\CommandeFournisseur::enAttente()->count())
    @if ($enAttente)
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2"><i class="bi bi-clipboard-check"></i>
            <span class="me-auto">{{ $enAttente }} commande(s) déjà passée(s) et pas encore reçue(s) : leurs quantités sont déduites des suggestions ci-dessous.</span>
            <a href="{{ route('commandes-fournisseur.index') }}" class="btn btn-sm btn-outline-primary">Voir les commandes</a></div>
    @endif

    @forelse ($parFournisseur as $fournisseurId => $lignes)
        @php($f = $fournisseurs[$fournisseurId] ?? null)
        <form method="post" action="{{ route('commandes-fournisseur.store') }}" class="bloc mb-3" data-sans-confirmation>
            @csrf
            <input type="hidden" name="fournisseur_id" value="{{ $f?->id }}">
            <div class="bloc-entete flex-wrap">
                <div><h2 class="mb-0">{{ $f?->nom ?? 'Sans fournisseur habituel' }}</h2>
                    @if ($f?->telephone)<div class="small text-doux">{{ numero_affiche($f->telephone) }}</div>@endif</div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <label class="small text-doux" for="prevue_{{ $fournisseurId }}">Livraison souhaitée</label>
                    <input type="date" name="livraison_prevue_le" id="prevue_{{ $fournisseurId }}" min="{{ now()->toDateString() }}" value="{{ now()->addDays(3)->toDateString() }}" class="form-control form-control-sm" style="width:auto">
                    <button class="btn btn-sm btn-primary"><i class="bi bi-clipboard-check me-1"></i>Enregistrer la commande</button>
                </div>
            </div>
            <div class="table-responsive"><table class="table mb-0">
                <thead><tr><th>Produit</th><th class="text-end">Stock</th><th class="text-end">Vendu / jour</th><th>Tient encore</th>
                    <th style="width:150px">À commander</th><th class="text-end">Coût estimé</th></tr></thead>
                <tbody>
                @foreach ($lignes as $s)
                    @php($p = $s['produit'])
                    <tr><td class="fw-semibold">{{ $p->designation }}</td>
                        <td class="text-end">{{ qte($p->stock) }} {{ $p->unite }}@if ($s['en_commande'])<div class="small text-primary text-nowrap">+ {{ qte($s['en_commande']) }} en commande</div>@endif</td>
                        <td class="text-end">{{ $s['par_jour'] ? qte($s['par_jour']) : '—' }}</td>
                        <td><span class="etat {{ $s['urgence'] === 'bientot' ? 'etat-alerte' : 'etat-rupture' }}">
                            {{ $s['urgence'] === 'rupture' ? 'Rupture' : ($s['jours_restants'] !== null ? $s['jours_restants'].' jour(s)' : 'Sous le seuil') }}</span></td>
                        <td><input type="number" step="0.01" min="0" name="quantites[{{ $p->id }}]" value="{{ $s['quantite'] }}" class="form-control form-control-sm text-end"
                                   aria-label="Quantité à commander de {{ $p->designation }}">
                            @if ($s['conditionnements'])<div class="small text-doux">= {{ $s['conditionnements'] }} {{ $p->conditionnement }}(s)</div>@endif</td>
                        <td class="text-end montant">{{ gnf($s['cout']) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        </form>
    @empty
        <div class="bloc vide mb-3"><i class="bi bi-check2-circle"></i>Rien à commander pour l'instant : le stock couvre les ventes prévues.</div>
    @endforelse

    <div class="bloc mt-4">
        <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-moon me-1"></i>Produits dormants (aucune vente depuis 60 jours)</h2>
            <span class="small text-doux">Argent immobilisé : <strong class="montant">{{ gnf($dormants->sum('valeur')) }}</strong></span></div>
        <div class="table-responsive"><table class="table mb-0"><tbody>
            @forelse ($dormants->take(30) as $d)
                <tr><td><a href="{{ route('produits.show', $d['produit']) }}">{{ $d['produit']->designation }}</a></td>
                    <td class="text-end">{{ qte($d['produit']->stock) }} {{ $d['produit']->unite }}</td>
                    <td class="small text-doux">{{ $d['derniere_vente'] ? 'dernière vente le '.\Carbon\Carbon::parse($d['derniere_vente'])->format('d/m/Y') : 'jamais vendu' }}</td>
                    <td class="text-end montant">{{ gnf($d['valeur']) }}</td></tr>
            @empty
                <tr><td class="vide">Aucun produit dormant.</td></tr>
            @endforelse
        </tbody></table></div>
        @if ($dormants->isNotEmpty())<div class="bloc-corps border-top small text-doux">Conseil : promotion, prix de gros, ou retour au fournisseur pour libérer de la trésorerie.</div>@endif
    </div>
@endsection
