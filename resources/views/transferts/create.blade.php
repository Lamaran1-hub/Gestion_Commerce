@extends('layouts.app')
@section('titre', 'Nouveau transfert')
@section('contenu')
    <div class="entete-page">
        <div><h1>Nouveau transfert</h1><div class="text-doux">Depuis « {{ boutique()->nom }} ». Le stock sort dès l'envoi.</div></div>
        <a href="{{ route('transferts.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Transferts</a>
    </div>
    <form method="post" action="{{ route('transferts.store') }}" id="formTransfert"
          data-confirmer="Envoyer cette marchandise ? Elle sort de votre stock tout de suite." data-confirmer-titre="Envoyer le transfert" data-confirmer-bouton="Oui, envoyer">
        @csrf
        <div class="bloc bloc-corps row g-3 mb-3">
            <div class="col-md-5"><label class="form-label" for="boutique_destination_id">Vers la boutique</label>
                <select name="boutique_destination_id" id="boutique_destination_id" class="form-select" required>
                    @foreach ($destinations as $d)<option value="{{ $d->id }}" @selected(old('boutique_destination_id') == $d->id)>{{ $d->nom }}{{ $d->ville ? ' — '.$d->ville : '' }}</option>@endforeach
                </select></div>
            <div class="col-md-7"><label class="form-label" for="note">Note (transporteur, véhicule…)</label>
                <input name="note" id="note" value="{{ old('note') }}" class="form-control" maxlength="500"></div>
        </div>
        <div class="bloc">
            <div class="bloc-entete">
                <div class="input-group" style="max-width:520px">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input id="choix" list="listeProduits" class="form-control" placeholder="Ajouter un produit en stock : nom ou code-barres" autocomplete="off">
                    <datalist id="listeProduits">@foreach ($produits as $p)<option value="{{ $p->designation }}{{ $p->code_barre ? ' ['.$p->code_barre.']' : '' }}">@endforeach</datalist>
                </div>
            </div>
            <div class="table-responsive"><table class="table mb-0 align-middle">
                <thead><tr><th>Produit</th><th class="text-end">En stock</th><th style="width:150px">Quantité à envoyer</th><th></th></tr></thead>
                <tbody id="lignes"><tr id="vide"><td colspan="4" class="vide">Recherchez un produit ci-dessus.</td></tr></tbody>
            </table></div>
        </div>
        <div class="mt-3"><button class="btn btn-primary btn-lg" id="envoyer" disabled><i class="bi bi-send me-1"></i>Envoyer le transfert</button></div>
    </form>
@endsection
@push('scripts')
<script>
(() => {
    const produits = {{ Js::from($produits->map(fn ($p) => ['id' => $p->id, 'nom' => $p->designation, 'code' => $p->code_barre, 'stock' => (float) $p->stock, 'unite' => $p->unite])) }};
    const tbody = document.getElementById('lignes');
    let n = 0;
    const echap = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const maj = () => { document.getElementById('envoyer').disabled = !tbody.querySelector('tr[data-produit]'); };
    document.getElementById('choix').addEventListener('change', (e) => {
        const v = e.target.value.trim();
        const p = produits.find(x => v === x.nom || (x.code && v.includes('[' + x.code + ']')) || v === x.code);
        if (!p) return;
        e.target.value = '';
        if (tbody.querySelector(`[data-produit="${p.id}"]`)) return tbody.querySelector(`[data-produit="${p.id}"] input`).focus();
        document.getElementById('vide')?.remove();
        const i = n++;
        tbody.insertAdjacentHTML('beforeend', `<tr data-produit="${p.id}"><td class="fw-semibold">${echap(p.nom)}<input type="hidden" name="lignes[${i}][produit_id]" value="${p.id}"></td>
            <td class="text-end">${p.stock.toLocaleString('fr-FR')} ${echap(p.unite)}</td>
            <td><input type="number" step="0.01" min="0.01" max="${p.stock}" name="lignes[${i}][quantite]" class="form-control form-control-sm text-end" required aria-label="Quantité"></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger" aria-label="Retirer">✕</button></td></tr>`);
        tbody.lastElementChild.querySelector('input[type=number]').focus();
        maj();
    });
    tbody.addEventListener('click', (e) => { if (e.target.closest('button')) { e.target.closest('tr').remove(); maj(); } });
})();
</script>
@endpush
