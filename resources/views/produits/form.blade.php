@extends('layouts.app')
@section('titre', $produit->exists ? 'Modifier '.$produit->designation : 'Nouveau produit')
@section('contenu')
    <div class="entete-page"><h1>{{ $produit->exists ? 'Modifier le produit' : 'Nouveau produit' }}</h1></div>
    <form method="post" enctype="multipart/form-data" action="{{ $produit->exists ? route('produits.update', $produit) : route('produits.store') }}" class="row g-4">
        @csrf @if ($produit->exists) @method('put') @endif
        <div class="col-lg-8">
            <div class="bloc bloc-corps row g-3">
                <div class="col-md-8"><label class="form-label" for="designation">Désignation</label>
                    <input name="designation" id="designation" value="{{ old('designation', $produit->designation) }}" class="form-control" required autofocus></div>
                <div class="col-md-4"><label class="form-label" for="code_barre">Code-barres</label>
                    <input name="code_barre" id="code_barre" value="{{ old('code_barre', $produit->code_barre) }}" class="form-control" placeholder="Scannez le produit">
                    <div class="form-text">Placez le curseur ici puis scannez.</div></div>
                <div class="col-md-4"><label class="form-label" for="categorie_id">Catégorie</label>
                    <div class="input-group"><select name="categorie_id" id="categorie_id" class="form-select"><option value="">Aucune</option>
                        @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('categorie_id', $produit->categorie_id) == $c->id)>{{ $c->nom }}</option>@endforeach</select>
                        <button type="button" class="btn btn-outline-primary" data-ajout-rapide="{{ route('categories.store') }}" data-cible="categorie_id" data-titre="Nouvelle catégorie" data-libelle="Nom de la catégorie" title="Nouvelle catégorie" aria-label="Nouvelle catégorie"><i class="bi bi-plus-lg"></i></button></div></div>
                <div class="col-md-4"><label class="form-label" for="fournisseur_id">Fournisseur</label>
                    <div class="input-group"><select name="fournisseur_id" id="fournisseur_id" class="form-select"><option value="">Aucun</option>
                        @foreach ($fournisseurs as $f)<option value="{{ $f->id }}" @selected(old('fournisseur_id', $produit->fournisseur_id) == $f->id)>{{ $f->nom }}</option>@endforeach</select>
                        @can('fournisseurs.gerer')<button type="button" class="btn btn-outline-primary" data-ajout-rapide="{{ route('fournisseurs.store') }}" data-cible="fournisseur_id" data-titre="Nouveau fournisseur" data-libelle="Nom du fournisseur" title="Nouveau fournisseur" aria-label="Nouveau fournisseur"><i class="bi bi-plus-lg"></i></button>@endcan</div></div>
                <div class="col-md-4"><label class="form-label" for="unite">Unité</label>
                    <input name="unite" id="unite" list="unites" value="{{ old('unite', $produit->unite) }}" class="form-control" required>
                    <datalist id="unites">@foreach (config('gestion.unites') as $u)<option value="{{ $u }}">@endforeach</datalist></div>
                <div class="col-md-4"><label class="form-label" for="prix_achat">Prix d'achat (GNF)</label>
                    <input name="prix_achat" id="prix_achat" data-montant inputmode="numeric" value="{{ old('prix_achat', $produit->prix_achat) }}" class="form-control text-end" required></div>
                <div class="col-md-4"><label class="form-label" for="prix_vente">Prix de vente{{ boutique()->tva_active ? (boutique()->prix_ttc ? ' TTC' : ' HT') : '' }} (GNF)</label>
                    <input name="prix_vente" id="prix_vente" data-montant inputmode="numeric" value="{{ old('prix_vente', $produit->prix_vente) }}" class="form-control text-end" required>
                    <div class="form-text" id="marge"></div>
                    {{-- Boutique assujettie : la TVA s'ajoute au prix hors taxe, le client paie le prix TTC --}}
                    @if (boutique()->tva_active)<div class="form-text text-primary fw-semibold" id="prixTtc" data-taux-boutique="{{ (float) boutique()->tva_taux }}" data-prix-ttc="{{ boutique()->prix_ttc ? 1 : 0 }}"></div>@endif</div>
                <div class="col-md-4"><label class="form-label" for="prix_gros">Prix de gros (GNF)</label>
                    <input name="prix_gros" id="prix_gros" data-montant inputmode="numeric" value="{{ old('prix_gros', $produit->prix_gros) }}" class="form-control text-end" placeholder="Facultatif"></div>
                <div class="col-md-4"><label class="form-label" for="quantite_gros">À partir de (quantité)</label>
                    <input type="number" step="0.01" min="2" name="quantite_gros" id="quantite_gros" value="{{ old('quantite_gros', $produit->quantite_gros ? (float) $produit->quantite_gros : '') }}" class="form-control" placeholder="Ex. : 10">
                    <div class="form-text">Prix de gros appliqué automatiquement à partir de cette quantité, et toujours pour les clients grossistes.</div></div>
                @if (boutique()->tva_active)
                    @php
                        $regime = old('regime_tva', $produit->taux_tva === null ? 'normal' : ((float) $produit->taux_tva == 0 ? 'exonere' : 'autre'));
                    @endphp
                    <div class="col-md-4"><label class="form-label" for="regime_tva">TVA</label>
                        <select name="regime_tva" id="regime_tva" class="form-select">
                            <option value="normal" @selected($regime === 'normal')>Taux normal ({{ \App\Support\Tva::libelle((float) boutique()->tva_taux) }})</option>
                            <option value="exonere" @selected($regime === 'exonere')>Exonéré (0 %)</option>
                            <option value="autre" @selected($regime === 'autre')>Autre taux…</option>
                        </select></div>
                    <div class="col-md-4 {{ $regime === 'autre' ? '' : 'd-none' }}" id="blocTauxAutre"><label class="form-label" for="taux_tva_autre">Taux (%)</label>
                        <input type="number" step="0.01" min="0" max="50" name="taux_tva_autre" id="taux_tva_autre" value="{{ old('taux_tva_autre', $regime === 'autre' ? (float) $produit->taux_tva : '') }}" class="form-control"></div>
                @endif
                @if (fonction('vitrine'))
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2">
                        <input type="hidden" name="en_vitrine" value="0">
                        <input type="checkbox" name="en_vitrine" value="1" id="en_vitrine" class="form-check-input" @checked(old('en_vitrine', $produit->en_vitrine ?? true))>
                        <label for="en_vitrine" class="form-check-label">Afficher dans la vitrine en ligne</label></div></div>
                @endif
                <div class="col-12"><h3 class="h6 mb-0 mt-2"><i class="bi bi-box2 me-1"></i>Vente par conditionnement <span class="text-doux fw-normal small">(facultatif)</span></h3></div>
                <div class="col-md-4"><label class="form-label" for="conditionnement">Conditionnement</label>
                    <input name="conditionnement" id="conditionnement" list="conditionnements" value="{{ old('conditionnement', $produit->conditionnement) }}" class="form-control" placeholder="Ex. : carton">
                    <datalist id="conditionnements">@foreach (['carton', 'casier', 'sac', 'paquet', 'boîte', 'fardeau', 'palette', 'douzaine'] as $c)<option value="{{ $c }}">@endforeach</datalist></div>
                <div class="col-md-4"><label class="form-label" for="qte_conditionnement">Nombre d'unités dedans</label>
                    <input type="number" step="0.01" min="2" name="qte_conditionnement" id="qte_conditionnement" value="{{ old('qte_conditionnement', $produit->qte_conditionnement ? (float) $produit->qte_conditionnement : '') }}" class="form-control" placeholder="Ex. : 12"></div>
                <div class="col-md-4"><label class="form-label" for="prix_conditionnement">Prix du conditionnement (GNF)</label>
                    <input name="prix_conditionnement" id="prix_conditionnement" data-montant inputmode="numeric" value="{{ old('prix_conditionnement', $produit->prix_conditionnement) }}" class="form-control text-end" placeholder="= unités × prix de détail">
                    <div class="form-text">Le stock reste compté à l'unité ; à la caisse, on choisit « à l'unité » ou « au carton ».</div></div>
                <div class="col-md-4"><label class="form-label" for="seuil_alerte">Seuil d'alerte</label>
                    <input type="number" step="0.01" min="0" name="seuil_alerte" id="seuil_alerte" value="{{ old('seuil_alerte', (float) ($produit->seuil_alerte ?? 0)) }}" class="form-control">
                    <div class="form-text">Alerte quand le stock descend à ce niveau.</div></div>
                <div class="col-md-4"><label class="form-label" for="garantie_mois">Garantie (mois)</label>
                    <input type="number" min="1" max="120" name="garantie_mois" id="garantie_mois" value="{{ old('garantie_mois', $produit->garantie_mois) }}" class="form-control" placeholder="Aucune">
                    <div class="form-text">Imprimée sur le reçu et la facture, avec la date de fin.</div></div>
                <div class="col-md-8 d-flex align-items-center"><div class="form-check form-switch">
                    <input type="hidden" name="suivi_serie" value="0">
                    <input type="checkbox" name="suivi_serie" value="1" id="suivi_serie" class="form-check-input" @checked(old('suivi_serie', $produit->suivi_serie))>
                    <label for="suivi_serie" class="form-check-label">Noter le numéro de série de chaque article vendu (IMEI, n° de fabrication)</label></div></div>
                @unless ($produit->exists)
                    <div class="col-md-4"><label class="form-label" for="stock_initial">Stock initial</label>
                        <input type="number" step="0.01" min="0" name="stock_initial" id="stock_initial" value="{{ old('stock_initial', 0) }}" class="form-control"></div>
                @else
                    <div class="col-md-8 d-flex align-items-end"><p class="text-doux small mb-2">Stock actuel : <strong>{{ qte($produit->stock) }} {{ $produit->unite }}</strong>.
                        Pour le modifier, passez par un approvisionnement ou un inventaire (l'historique reste ainsi traçable).</p></div>
                @endunless
                <div class="col-12"><div class="form-check form-switch">
                    <input type="hidden" name="actif" value="0">
                    <input type="checkbox" name="actif" value="1" id="actif" class="form-check-input" @checked(old('actif', $produit->actif))>
                    <label for="actif" class="form-check-label">Produit en vente (visible à la caisse)</label></div></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="bloc bloc-corps">
                <label class="form-label" for="image">Photo du produit</label>
                @if ($produit->imageUrl())<img src="{{ $produit->imageUrl() }}" alt="" class="d-block mb-2 rounded border" style="max-width:100%;max-height:180px">@endif
                <input type="file" name="image" id="image" accept="image/*" class="form-control">
                <div class="form-text">Facultatif. 2 Mo maximum.</div>
            </div>
            <div class="d-grid gap-2 mt-3">
                <button class="btn btn-primary btn-lg">{{ $produit->exists ? 'Enregistrer les modifications' : 'Ajouter le produit' }}</button>
                @unless ($produit->exists)<button name="continuer" value="1" class="btn btn-outline-primary">Ajouter et saisir un autre</button>@endunless
                <a href="{{ route('produits.index') }}" class="btn btn-light">Annuler</a>
            </div>
        </div>
    </form>
    @if ($produit->exists)
        <form method="post" action="{{ route('produits.destroy', $produit) }}" class="mt-4" data-confirmer="Supprimer ce produit ? Il n'apparaîtra plus à la caisse, mais reste dans l'historique des ventes.">
            @csrf @method('delete')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Supprimer ce produit</button>
        </form>
    @endif
@endsection
@push('scripts')
<script>
    document.getElementById('regime_tva')?.addEventListener('change', (e) => {
        document.getElementById('blocTauxAutre').classList.toggle('d-none', e.target.value !== 'autre');
    });
    const pa = document.getElementById('prix_achat'), pv = document.getElementById('prix_vente'), m = document.getElementById('marge');
    const calc = () => { const a = nombre(pa.value), v = nombre(pv.value);
        m.textContent = v ? `Marge : ${gnf(v - a)}${a ? ' (' + Math.round((v - a) / a * 100) + ' %)' : ''}` : ''; m.className = 'form-text ' + (v < a ? 'text-danger' : ''); };
    [pa, pv].forEach(i => i.addEventListener('input', calc)); calc();
    // Prix payé par le client (TTC) selon le régime de TVA du produit
    const ttc = document.getElementById('prixTtc');
    if (ttc) {
        const majTtc = () => {
            const regime = document.getElementById('regime_tva')?.value || 'normal';
            const taux = regime === 'exonere' ? 0 : (regime === 'autre' ? (parseFloat(String(document.getElementById('taux_tva_autre')?.value || 0).replace(',', '.')) || 0) : Number(ttc.dataset.tauxBoutique));
            const v = nombre(pv.value);
            const tauxTexte = String(taux).replace('.', ',');
            ttc.textContent = !v ? '' : (!taux ? 'Produit exonéré : le client paie ce prix, sans TVA'
                // Prix TTC : le client paie le prix saisi, la TVA en est extraite ; prix HT : la TVA s'y ajoute
                : (ttc.dataset.prixTtc === '1' ? `Le client paie ${gnf(v)}, dont ${gnf(Math.round(v * taux / (100 + taux)))} de TVA (${tauxTexte} %)`
                    : `Le client paie ${gnf(Math.round(v * (1 + taux / 100)))} TTC (TVA ${tauxTexte} %)`));
        };
        [pv, document.getElementById('regime_tva'), document.getElementById('taux_tva_autre')].forEach(e => e?.addEventListener('input', majTtc));
        document.getElementById('regime_tva')?.addEventListener('change', majTtc);
        majTtc();
    }
</script>
@endpush
