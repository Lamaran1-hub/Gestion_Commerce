@extends('layouts.app')
@section('titre', $plan->exists ? 'Modifier la formule' : 'Nouvelle formule')
@section('contenu')
    <div class="entete-page"><h1>{{ $plan->exists ? 'Formule '.$plan->nom : 'Nouvelle formule' }}</h1></div>
    <form method="post" action="{{ $plan->exists ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" class="bloc bloc-corps row g-3" style="max-width:760px">
        @csrf @if ($plan->exists) @method('put') @endif
        <div class="col-md-6"><label class="form-label" for="nom">Nom</label><input name="nom" id="nom" value="{{ old('nom', $plan->nom) }}" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label" for="prix_mensuel">Prix mensuel (GNF)</label><input name="prix_mensuel" id="prix_mensuel" data-montant value="{{ old('prix_mensuel', $plan->prix_mensuel) }}" class="form-control text-end" required></div>
        <div class="col-md-6"><label class="form-label" for="max_utilisateurs">Utilisateurs maximum</label><input type="number" min="1" name="max_utilisateurs" id="max_utilisateurs" value="{{ old('max_utilisateurs', $plan->max_utilisateurs) }}" class="form-control"><div class="form-text">Vide = illimité.</div></div>
        <div class="col-md-6"><label class="form-label" for="max_produits">Produits maximum</label><input type="number" min="1" name="max_produits" id="max_produits" value="{{ old('max_produits', $plan->max_produits) }}" class="form-control"><div class="form-text">Vide = illimité.</div></div>
        <div class="col-md-6"><label class="form-label" for="max_boutiques">Boutiques (points de vente) maximum</label><input type="number" min="1" name="max_boutiques" id="max_boutiques" value="{{ old('max_boutiques', $plan->max_boutiques) }}" class="form-control"><div class="form-text">Pour un commerçant qui a plusieurs points de vente. Vide = illimité.</div></div>
        <div class="col-12">
            <span class="form-label d-block">Fonctions incluses</span>
            <div class="form-check form-switch mb-2"><input type="checkbox" name="toutes_fonctions" value="1" id="toutes_fonctions" class="form-check-input"
                @checked(old('toutes_fonctions', $plan->exists ? $plan->fonctions === null : false))>
                <label for="toutes_fonctions" class="form-check-label fw-semibold">Toutes les fonctions (y compris celles ajoutées plus tard)</label></div>
            <div class="row g-1" id="listeFonctions">
                @foreach (config('gestion.fonctions') as $cle => [$libelle, $icone])
                    <div class="col-md-6"><div class="form-check">
                        <input type="checkbox" name="fonctions[]" value="{{ $cle }}" id="f_{{ $cle }}" class="form-check-input"
                            @checked(in_array($cle, old('fonctions', $plan->fonctions ?? []), true))>
                        <label for="f_{{ $cle }}" class="form-check-label"><i class="bi bi-{{ $icone }} me-1 text-doux"></i>{{ $libelle }}</label></div></div>
                @endforeach
            </div>
            <div class="form-text">Toujours inclus : caisse, stock, clients, crédits, devis et factures proforma, dépenses, fournisseurs et rapports de base. Vous pouvez aussi accorder une fonction à un client précis depuis sa fiche.</div>
        </div>
        <div class="col-12"><label class="form-label" for="description">Description</label><textarea name="description" id="description" rows="2" class="form-control">{{ old('description', $plan->description) }}</textarea></div>
        <div class="col-12"><div class="form-check form-switch"><input type="checkbox" name="actif" value="1" id="actif" class="form-check-input" @checked(old('actif', $plan->actif))>
            <label for="actif" class="form-check-label">Proposée aux nouvelles boutiques</label></div></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Enregistrer</button><a href="{{ route('admin.plans.index') }}" class="btn btn-light">Annuler</a></div>
    </form>
@endsection
@push('scripts')
<script>
(() => {
    const toutes = document.getElementById('toutes_fonctions');
    const maj = () => document.querySelectorAll('#listeFonctions input').forEach(i => { i.disabled = toutes.checked; if (toutes.checked) i.checked = true; });
    toutes.addEventListener('change', maj);
    maj();
})();
</script>
@endpush
