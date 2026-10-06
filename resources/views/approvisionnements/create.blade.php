@extends('layouts.app')
@section('titre', 'Nouvelle réception')
@section('contenu')
    <div class="entete-page"><div><h1>Réception de marchandise</h1>
        <div class="text-doux">Le stock augmente et le prix d'achat de chaque produit est mis à jour.</div></div></div>
    @php($commandeId = old('commande_fournisseur_id', $commande?->id))
    @if ($commande)
        <div class="alert alert-info"><i class="bi bi-clipboard-check me-1"></i>Réception de la commande <a href="{{ route('commandes-fournisseur.show', $commande) }}" class="alert-link">{{ $commande->numero }}</a> :
            les quantités restant à recevoir sont préremplies. Corrigez-les selon ce qui est vraiment arrivé.</div>
    @endif
    <form method="post" action="{{ route('approvisionnements.store') }}" id="formAppro">
        @csrf
        @if ($commandeId)<input type="hidden" name="commande_fournisseur_id" value="{{ $commandeId }}">@endif
        <div class="bloc bloc-corps row g-3 mb-3">
            <div class="col-md-5"><label class="form-label" for="fournisseur_id">Fournisseur</label>
                <div class="input-group"><select name="fournisseur_id" id="fournisseur_id" class="form-select"><option value="">Non précisé</option>
                    @foreach ($fournisseurs as $f)<option value="{{ $f->id }}" @selected(old('fournisseur_id', $commande?->fournisseur_id) == $f->id)>{{ $f->nom }}</option>@endforeach</select>
                    @can('fournisseurs.gerer')<button type="button" class="btn btn-outline-primary" data-ajout-rapide="{{ route('fournisseurs.store') }}" data-cible="fournisseur_id" data-titre="Nouveau fournisseur" data-libelle="Nom du fournisseur" title="Nouveau fournisseur" aria-label="Nouveau fournisseur"><i class="bi bi-plus-lg"></i></button>@endcan</div></div>
            <div class="col-md-3"><label class="form-label" for="date_appro">Date de réception</label>
                <input type="date" name="date_appro" id="date_appro" value="{{ old('date_appro', now()->toDateString()) }}" max="{{ now()->toDateString() }}"
                    @if (boutique()->periode_verrouillee_jusquau) min="{{ boutique()->periode_verrouillee_jusquau->copy()->addDay()->toDateString() }}" title="Période clôturée jusqu'au {{ boutique()->periode_verrouillee_jusquau->format('d/m/Y') }}" @endif
                    class="form-control" required></div>
            <div class="col-md-4"><label class="form-label" for="note">Note (n° de bon, facture...)</label>
                <input name="note" id="note" value="{{ old('note') }}" class="form-control"></div>
        </div>
        <div class="bloc">
            <div class="bloc-entete">
                <div class="input-group" style="max-width:520px">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input id="choix" list="listeProduits" class="form-control" placeholder="Ajouter un produit : nom ou code-barres" autocomplete="off">
                    <datalist id="listeProduits">@foreach ($produits as $p)<option value="{{ $p->designation }}{{ $p->code_barre ? ' ['.$p->code_barre.']' : '' }}">@endforeach</datalist>
                </div>
                <a href="{{ route('produits.create') }}" class="small" target="_blank">Produit absent ? Créez-le</a>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Produit</th><th class="text-end">Stock actuel</th><th style="width:130px">Quantité reçue</th>
                        <th style="width:170px">Prix d'achat (de l'unité reçue)</th><th style="width:170px">Nouveau prix de vente (à l'unité)</th><th class="text-end">Total</th><th></th></tr></thead>
                    <tbody id="lignes"><tr id="vide"><td colspan="7" class="vide">Recherchez un produit ci-dessus pour l'ajouter à la réception.</td></tr></tbody>
                    <tfoot><tr><td colspan="5" class="text-end fw-bold">Montant total</td><td class="text-end fw-bold montant" id="total">0 GNF</td><td></td></tr></tfoot>
                </table>
            </div>
        </div>
        <div class="bloc bloc-corps row g-3 mt-3">
            <div class="col-12"><h2 class="h6 mb-0"><i class="bi bi-cash-coin me-1"></i>Règlement du fournisseur</h2></div>
            <div class="col-md-4">
                @foreach (['comptant' => 'Payé comptant', 'partiel' => 'Payé en partie', 'credit' => 'À crédit (payé plus tard)'] as $k => $lib)
                    <div class="form-check"><input type="radio" name="reglement" value="{{ $k }}" id="reglement_{{ $k }}" class="form-check-input" @checked(old('reglement', 'comptant') === $k)>
                        <label for="reglement_{{ $k }}" class="form-check-label">{{ $lib }}</label></div>
                @endforeach
            </div>
            <div class="col-md-3" id="blocMontantPaye"><label class="form-label" for="montant_paye">Montant payé maintenant</label>
                <input name="montant_paye" id="montant_paye" data-montant inputmode="numeric" value="{{ old('montant_paye') }}" class="form-control text-end"></div>
            <div class="col-md-3" id="blocModeReglement"><label class="form-label" for="mode_reglement">Payé par</label>
                <select name="mode_reglement" id="mode_reglement" class="form-select">
                    @foreach (config('gestion.modes_paiement') as $k => $lib)<option value="{{ $k }}" @selected(old('mode_reglement', 'especes') === $k)>{{ $lib }}</option>@endforeach
                </select></div>
            <div class="col-md-2" id="blocEcheance"><label class="form-label" for="echeance">Échéance</label>
                <input type="date" name="echeance" id="echeance" value="{{ old('echeance', now()->addDays(\App\Services\DetteFournisseurService::ECHEANCE_PAR_DEFAUT_JOURS)->toDateString()) }}" class="form-control"></div>
            <div class="col-12 form-text mt-0">Payé en espèces : le montant est déduit de votre caisse du jour. Non payé ou payé en partie : le reste devient une dette envers le fournisseur (choix du fournisseur obligatoire).</div>
        </div>
        <div class="mt-3 d-flex gap-2"><button class="btn btn-primary btn-lg" id="valider" disabled>Enregistrer la réception</button>
            <a href="{{ route('approvisionnements.index') }}" class="btn btn-light btn-lg">Annuler</a></div>
    </form>
@endsection
@push('scripts')
<script>
(() => {
    const produits = @json($produits);
    const tbody = document.getElementById('lignes');
    let n = 0;

    function ajouter(p, qte = '', pau = null, pv = '') {
        if (tbody.querySelector(`[data-produit="${p.id}"]`)) return tbody.querySelector(`[data-produit="${p.id}"] .q`).focus();
        document.getElementById('vide')?.remove();
        const i = n++;
        tbody.insertAdjacentHTML('beforeend', `
          <tr data-produit="${p.id}">
            <td class="fw-semibold">${p.designation}<input type="hidden" name="lignes[${i}][produit_id]" value="${p.id}">
                @if (fonction('peremptions'))<label class="d-flex align-items-center gap-1 small fw-normal text-doux mt-1">Périme le <input type="date" name="lignes[${i}][date_peremption]" class="form-control form-control-sm" style="max-width:150px" aria-label="Date de péremption (facultatif)"></label>@endif</td>
            <td class="text-end">${Number(p.stock).toLocaleString('fr-FR')} ${p.unite}</td>
            <td><input type="number" step="0.01" min="0.01" name="lignes[${i}][quantite]" value="${qte}" class="form-control form-control-sm text-end q" required aria-label="Quantité">
                ${p.conditionnement && Number(p.qte_conditionnement) > 1 ? `<select name="lignes[${i}][conditionnement]" class="form-select form-select-sm mt-1 unite" aria-label="Unité reçue">
                    <option value="0">${p.unite}</option><option value="1">${p.conditionnement} de ${Number(p.qte_conditionnement).toLocaleString('fr-FR')}</option></select>` : ''}</td>
            <td><input name="lignes[${i}][prix_achat_unitaire]" data-montant value="${pau ?? p.prix_achat}" class="form-control form-control-sm text-end pau" required inputmode="numeric" aria-label="Prix d'achat"></td>
            <td><input name="lignes[${i}][prix_vente]" data-montant value="${pv}" placeholder="${Number(p.prix_vente).toLocaleString('fr-FR')}" class="form-control form-control-sm text-end" inputmode="numeric" aria-label="Nouveau prix de vente"></td>
            <td class="text-end montant ligne-total">0 GNF</td>
            <td><button type="button" class="btn btn-sm btn-link text-danger retirer" aria-label="Retirer">✕</button></td>
          </tr>`);
        tbody.querySelectorAll('[data-montant]').forEach(el => el.dispatchEvent(new Event('input', { bubbles: true })));
        tbody.querySelector(`[data-produit="${p.id}"] .q`).focus();
        calculer();
    }

    function calculer() {
        let total = 0;
        tbody.querySelectorAll('tr[data-produit]').forEach(tr => {
            const t = Math.round((parseFloat(tr.querySelector('.q').value) || 0) * nombre(tr.querySelector('.pau').value));
            tr.querySelector('.ligne-total').textContent = gnf(t);
            total += t;
        });
        document.getElementById('total').textContent = gnf(total);
        document.getElementById('valider').disabled = !tbody.querySelector('tr[data-produit]');
    }

    document.getElementById('choix').addEventListener('change', (e) => {
        const v = e.target.value.trim().toLowerCase();
        const p = produits.find(p => (p.designation + (p.code_barre ? ` [${p.code_barre}]` : '')).toLowerCase() === v)
            || produits.find(p => p.code_barre && p.code_barre.toLowerCase() === v)
            || produits.find(p => p.designation.toLowerCase() === v);
        if (p) { ajouter(p); e.target.value = ''; }
    });
    document.getElementById('choix').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); e.target.dispatchEvent(new Event('change')); } });
    tbody.addEventListener('input', calculer);
    // Réception au carton : le prix d'achat proposé devient celui d'un carton complet
    tbody.addEventListener('change', (e) => {
        if (!e.target.matches('select.unite')) return;
        const tr = e.target.closest('tr'), p = produits.find(x => x.id == tr.dataset.produit), pau = tr.querySelector('.pau');
        const facteur = e.target.value === '1' ? Number(p.qte_conditionnement) : 1;
        pau.value = Math.round(p.prix_achat * facteur);
        pau.dispatchEvent(new Event('input', { bubbles: true }));
    });
    tbody.addEventListener('click', (e) => { if (e.target.closest('.retirer')) { e.target.closest('tr').remove(); calculer(); } });

    @foreach (old('lignes', []) as $l)
        { const p = produits.find(x => x.id == {{ (int) ($l['produit_id'] ?? 0) }}); if (p) ajouter(p, @json($l['quantite'] ?? ''), @json($l['prix_achat_unitaire'] ?? null), @json($l['prix_vente'] ?? '')); }
    @endforeach
    @if ($commande && ! old('lignes'))
        @foreach ($commande->lignes->filter(fn ($l) => $l->reste() > 0) as $l)
            { const p = produits.find(x => x.id == {{ (int) $l->produit_id }}); if (p) ajouter(p, @json($l->reste())); }
        @endforeach
    @endif
    @if ($produitChoisi && ! old('lignes'))
        { const p = produits.find(x => x.id == {{ $produitChoisi }}); if (p) ajouter(p); }
    @endif
})();

// Règlement : n'afficher que les champs utiles au choix
(() => {
    const radios = document.querySelectorAll('input[name="reglement"]');
    const maj = () => {
        const r = document.querySelector('input[name="reglement"]:checked')?.value;
        document.getElementById('blocMontantPaye').classList.toggle('d-none', r !== 'partiel');
        document.getElementById('blocModeReglement').classList.toggle('d-none', r === 'credit');
        document.getElementById('blocEcheance').classList.toggle('d-none', r === 'comptant');
        document.getElementById('montant_paye').required = r === 'partiel';
    };
    radios.forEach(r => r.addEventListener('change', maj)); maj();
})();
</script>
@endpush
