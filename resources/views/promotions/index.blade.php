@extends('layouts.app')
@section('titre', 'Promotions')
@section('contenu')
    <div class="entete-page">
        <div><h1>Promotions</h1>
            <div class="text-doux">Réductions à durée limitée, appliquées automatiquement à la caisse et sur les devis.</div></div>
    </div>
    <div class="row g-3">
        <div class="col-lg-4">
            <form method="post" action="{{ route('promotions.store') }}" class="bloc bloc-corps" data-sans-confirmation>
                @csrf
                <h2 class="h6">Nouvelle promotion</h2>
                <label class="form-label small" for="nom">Nom</label>
                <input name="nom" id="nom" value="{{ old('nom') }}" class="form-control mb-2" maxlength="100" placeholder="Ex. : Fête de fin d'année" required>
                <label class="form-label small" for="portee">S'applique à</label>
                <select name="portee" id="portee" class="form-select mb-2">
                    <option value="produit" @selected(old('portee', 'produit') === 'produit')>Un produit</option>
                    <option value="categorie" @selected(old('portee') === 'categorie')>Une catégorie</option>
                    <option value="tout" @selected(old('portee') === 'tout')>Toute la boutique</option>
                </select>
                <div data-portee="produit"><select name="produit_id" class="form-select mb-2" aria-label="Produit">
                    <option value="">Choisir le produit…</option>
                    @foreach ($produits as $p)<option value="{{ $p->id }}" @selected(old('produit_id') == $p->id)>{{ $p->designation }} — {{ gnf($p->prix_vente) }}</option>@endforeach
                </select></div>
                <div data-portee="categorie"><select name="categorie_id" class="form-select mb-2" aria-label="Catégorie">
                    <option value="">Choisir la catégorie…</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('categorie_id') == $c->id)>{{ $c->nom }}</option>@endforeach
                </select></div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small" for="type">Réduction</label>
                        <select name="type" id="type" class="form-select">
                            <option value="pourcentage" @selected(old('type', 'pourcentage') === 'pourcentage')>En %</option>
                            <option value="prix" @selected(old('type') === 'prix')>Prix fixe</option>
                        </select></div>
                    <div class="col-6"><label class="form-label small" for="valeur" id="libelleValeur">Pourcentage</label>
                        <input name="valeur" id="valeur" value="{{ old('valeur') }}" class="form-control text-end" inputmode="decimal" required></div>
                    <div class="col-6"><label class="form-label small" for="debut">Du</label>
                        <input type="date" name="debut" id="debut" value="{{ old('debut', now()->toDateString()) }}" class="form-control" required></div>
                    <div class="col-6"><label class="form-label small" for="fin">Au (inclus)</label>
                        <input type="date" name="fin" id="fin" value="{{ old('fin', now()->addDays(7)->toDateString()) }}" class="form-control" required></div>
                </div>
                <div class="form-text">Le client paie toujours le plus bas du prix promo et du prix de gros. Une promotion ne fait jamais vendre sous le prix d'achat (sauf si la vente à perte est autorisée).</div>
                <button class="btn btn-primary w-100 mt-3">Enregistrer la promotion</button>
            </form>
        </div>
        <div class="col-lg-8">
            <div class="bloc"><div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Promotion</th><th>Portée</th><th>Réduction</th><th>Période</th><th>État</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($promotions as $pr)
                        @php($etat = $pr->etat())
                        <tr><td class="fw-semibold">{{ $pr->nom }}</td><td>{{ $pr->portee() }}</td><td>{{ $pr->libelleReduction() }}</td>
                            <td class="small">{{ $pr->debut->format('d/m/Y') }} → {{ $pr->fin->format('d/m/Y') }}</td>
                            <td><span class="etat {{ ['En cours' => 'etat-ok', 'À venir' => 'etat-alerte'][$etat] ?? 'etat-rupture' }}">{{ $etat }}</span></td>
                            <td class="text-end text-nowrap">
                                @if ($etat !== 'Terminée')
                                    <form method="post" action="{{ route('promotions.basculer', $pr) }}" class="d-inline" data-sans-confirmation>@csrf
                                        <button class="btn btn-sm btn-light">{{ $pr->actif ? 'Arrêter' : 'Relancer' }}</button></form>
                                @endif
                                <form method="post" action="{{ route('promotions.destroy', $pr) }}" class="d-inline"
                                      data-confirmer="Supprimer cette promotion ?" data-confirmer-titre="Supprimer" data-confirmer-bouton="Oui, supprimer">@csrf @method('delete')
                                    <button class="btn btn-sm btn-link text-danger" aria-label="Supprimer">✕</button></form>
                            </td></tr>
                    @empty
                        <tr><td colspan="6" class="vide">Aucune promotion. Créez-en une pour écouler un stock ou animer une période de fête.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div></div>
            <div class="mt-2">{{ $promotions->links() }}</div>
        </div>
    </div>
@endsection
@push('scripts')
<script>
(() => {
    const maj = () => {
        const portee = document.getElementById('portee').value;
        document.querySelectorAll('[data-portee]').forEach(el => {
            el.classList.toggle('d-none', el.dataset.portee !== portee);
            el.querySelector('select').disabled = el.dataset.portee !== portee;
        });
        const prix = document.getElementById('type').value === 'prix';
        document.getElementById('libelleValeur').textContent = prix ? 'Prix promo (GNF)' : 'Pourcentage';
    };
    ['portee', 'type'].forEach(id => document.getElementById(id).addEventListener('change', maj));
    maj();
})();
</script>
@endpush
