@extends('layouts.app')
@section('titre', 'Dépenses')
@section('contenu')
    @php
        $e = $depenseEdition;
        $prevues = config('gestion.categories_depense');
        $categorieActuelle = old('categorie', $e?->categorie);
        // Une catégorie personnalisée s'affiche comme « Autre » avec sa précision
        $precisionAutre = old('categorie_autre', $categorieActuelle && ! in_array($categorieActuelle, $prevues, true) ? $categorieActuelle : '');
    @endphp
    <div class="entete-page">
        <div><h1>Dépenses</h1><div class="text-doux">Total de la période : <strong class="montant">{{ gnf($total) }}</strong></div></div>
        @can('rapports.voir')<a href="{{ route('rapports.export', ['depenses', 'excel', 'du' => $du, 'au' => $au]) }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>@endcan
    </div>
    <div class="row g-4">
        <div class="col-lg-4">
            <form method="post" action="{{ $e ? route('depenses.update', $e) : route('depenses.store') }}" class="bloc">
                @csrf @if ($e) @method('put') @endif
                <div class="bloc-entete"><h2 class="mb-0">{{ $e ? 'Modifier la dépense' : 'Nouvelle dépense' }}</h2></div>
                <div class="bloc-corps row g-3">
                    <div class="col-12"><label class="form-label" for="motif">Motif</label>
                        <input name="motif" id="motif" value="{{ old('motif', $e?->motif) }}" class="form-control" required placeholder="Ex. : Facture EDG de mars"></div>
                    <div class="col-6"><label class="form-label" for="montant">Montant (GNF)</label>
                        <input name="montant" id="montant" data-montant inputmode="numeric" value="{{ old('montant', $e?->montant) }}" class="form-control text-end" required></div>
                    <div class="col-6"><label class="form-label" for="date_depense">Date</label>
                        <input type="date" name="date_depense" id="date_depense" value="{{ old('date_depense', $e?->date_depense?->toDateString() ?? now()->toDateString()) }}" max="{{ now()->toDateString() }}"
                            @if (boutique()->periode_verrouillee_jusquau) min="{{ boutique()->periode_verrouillee_jusquau->copy()->addDay()->toDateString() }}" title="Période clôturée jusqu'au {{ boutique()->periode_verrouillee_jusquau->format('d/m/Y') }}" @endif
                            class="form-control" required></div>
                    <div class="col-12"><label class="form-label" for="mode_depense">Payée par</label>
                        <select name="mode" id="mode_depense" class="form-select">
                            @foreach (config('gestion.modes_paiement') as $k => $lib)<option value="{{ $k }}" @selected(old('mode', $e?->mode ?? 'especes') === $k)>{{ $lib }}</option>@endforeach
                        </select>
                        <div class="form-text">En espèces : le montant est déduit de votre caisse du jour (clôture).</div></div>
                    <div class="col-12"><label class="form-label" for="categorie">Catégorie</label>
                        <select name="categorie" id="categorie" class="form-select" data-autre data-autre-valeur="{{ $precisionAutre }}" data-autre-placeholder="Précisez la catégorie (facultatif)">
                            @foreach ($prevues as $c)<option @selected($precisionAutre ? $c === 'Autre' : $categorieActuelle === $c)>{{ $c }}</option>@endforeach</select></div>
                    <div class="col-12"><label class="form-label" for="note">Note</label><textarea name="note" id="note" rows="2" class="form-control">{{ old('note', $e?->note) }}</textarea></div>
                    <div class="col-12 d-flex gap-2"><button class="btn btn-primary">{{ $e ? 'Enregistrer' : 'Ajouter la dépense' }}</button>
                        @if ($e)<a href="{{ route('depenses.index') }}" class="btn btn-light">Annuler</a>@endif</div>
                </div>
            </form>
            @if ($parCategorie->isNotEmpty())
                <div class="bloc mt-4">
                    <div class="bloc-entete"><h2 class="mb-0">Par catégorie</h2></div>
                    <div class="bloc-corps">
                        @foreach ($parCategorie as $cat => $t)
                            <div class="d-flex justify-content-between py-1"><span>{{ $cat }}</span><span class="montant">{{ gnf($t) }}</span></div>
                            <div class="progress mb-2" style="height:5px"><div class="progress-bar" style="width:{{ $total ? round($t / $total * 100) : 0 }}%;background:var(--marque)"></div></div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
        <div class="col-lg-8">
            <form class="bloc bloc-corps row g-2 align-items-end mb-3">
                @include('partials.periode')
                <div class="col-md"><label class="form-label small text-doux mb-1" for="fcat">Catégorie</label>
                    <select name="categorie" id="fcat" class="form-select form-select-sm"><option value="">Toutes</option>
                        @foreach ($categories as $c)<option @selected(request('categorie') === $c)>{{ $c }}</option>@endforeach</select></div>
                <div class="col-md-auto"><button class="btn btn-sm btn-primary">Filtrer</button></div>
            </form>
            <div class="bloc">
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Motif</th><th>Catégorie</th><th>Payée par</th><th class="text-end">Montant</th><th>Par</th><th></th></tr></thead>
                        <tbody>
                        @forelse ($depenses as $d)
                            <tr><td>{{ $d->date_depense->format('d/m/Y') }}</td><td>{{ $d->motif }}@if ($d->note)<div class="small text-doux">{{ $d->note }}</div>@endif</td>
                                <td>{{ $d->categorie }}</td><td class="small">{{ config('gestion.modes_paiement')[$d->mode] ?? $d->mode }}</td><td class="text-end montant fw-semibold">{{ gnf($d->montant) }}</td><td class="text-doux small">{{ $d->auteur?->prenom }}</td>
                                <td class="text-end text-nowrap">@if ($issuesDeCommission->has($d->id))
                                        <a href="{{ route('commissions.index') }}" class="btn btn-sm btn-light" title="Versement de commission : se gère depuis la page Commissions"><i class="bi bi-cash-stack me-1"></i>Commission</a>
                                    @else<a href="{{ route('depenses.index', ['modifier' => $d->id] + request()->query()) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                                    <form method="post" action="{{ route('depenses.destroy', $d) }}" class="d-inline" data-confirmer="Supprimer cette dépense ?">@csrf @method('delete')
                                        <button class="btn btn-sm btn-light text-danger" aria-label="Supprimer"><i class="bi bi-trash"></i></button></form>@endif</td></tr>
                        @empty
                            <tr><td colspan="7" class="vide"><i class="bi bi-wallet2"></i>Aucune dépense sur cette période.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-3">{{ $depenses->links() }}</div>
        </div>
    </div>
@endsection
