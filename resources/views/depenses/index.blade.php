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
            {{-- Charges fixes du mois : loyer, électricité, salaires… rappelés et payés en un clic --}}
            @php
                $etats = ['payee' => ['payée', 'etat-ok'], 'retard' => ['en retard', 'etat-rupture'], 'bientot' => ['à payer bientôt', 'etat-alerte'], 'a_venir' => ['à venir', 'etat-neutre']];
                $resteCharges = $charges->where('etat', '!=', 'payee')->sum(fn ($c) => $c['charge']->montant);
                $moisCharges = now()->translatedFormat('F Y');
                $deMois = (preg_match('/^[aeiouyéèâ]/iu', $moisCharges) ? "d'" : 'de ').$moisCharges;   // « d'octobre », « de mars »
            @endphp
            <div class="bloc mb-3" id="charges">
                <div class="bloc-entete flex-wrap gap-1"><h2 class="mb-0"><i class="bi bi-calendar2-check me-1"></i>Charges fixes {{ $deMois }}</h2>
                    @if ($charges->isNotEmpty())<span class="small text-doux">{{ gnf($totalCharges) }} par mois · reste à payer <strong class="{{ $resteCharges ? 'text-danger' : 'text-success' }}">{{ gnf($resteCharges) }}</strong></span>@endif</div>
                @if ($charges->isNotEmpty())
                    <div class="table-responsive"><table class="table mb-0 align-middle">
                        <tbody>
                        @foreach ($charges as $c)
                            @php($ch = $c['charge'])
                            <tr class="{{ $c['etat'] === 'retard' ? 'table-warning' : '' }}">
                                <td><div class="fw-semibold">{{ $ch->motif }}</div>
                                    <div class="small text-doux">le {{ $ch->jour }} du mois{{ $ch->categorie ? ' · '.$ch->categorie : '' }} · <span class="text-nowrap">{{ gnf($ch->montant) }}</span></div></td>
                                <td class="text-nowrap"><span class="etat {{ $etats[$c['etat']][1] }}">{{ $etats[$c['etat']][0] }}</span>
                                    <div class="small text-doux">@if ($c['paiement'])le {{ $c['paiement']->date_depense->format('d/m') }} · {{ gnf($c['paiement']->montant) }}
                                        @elseif ($c['jours'] < 0)depuis le {{ $c['echeance']->format('d/m') }}@elseif ($c['jours'] === 0)aujourd'hui@else le {{ $c['echeance']->format('d/m') }}@endif</div></td>
                                <td class="text-end d-none d-md-table-cell">@unless ($c['paiement'])@include('depenses._payer-charge', ['ch' => $ch])@endunless</td>
                            </tr>
                            @unless ($c['paiement'])<tr class="d-md-none {{ $c['etat'] === 'retard' ? 'table-warning' : '' }}"><td colspan="2" class="text-end border-top-0 pt-0">@include('depenses._payer-charge', ['ch' => $ch])</td></tr>@endunless
                        @endforeach
                        </tbody>
                    </table></div>
                @else
                    <p class="bloc-corps small text-doux mb-0">Loyer, électricité, salaires, abonnements… Déclarez-les une fois : ils seront rappelés chaque mois ici, et sur le tableau de bord quand l'échéance approche.</p>
                @endif
                <details class="border-top" @if ($errors->has('jour')) open @endif>
                    <summary class="bloc-corps py-2 small fw-semibold" style="cursor:pointer"><i class="bi bi-plus-lg me-1"></i>Ajouter ou modifier une charge fixe</summary>
                    <div class="bloc-corps pt-0">
                        <form method="post" action="{{ route('charges-fixes.store') }}" class="row g-2 align-items-end">@csrf
                            <div class="col-sm-4"><label class="form-label small mb-1" for="cf_motif">Motif</label><input name="motif" id="cf_motif" class="form-control form-control-sm" required placeholder="Ex. : Loyer du magasin"></div>
                            <div class="col-6 col-sm-2"><label class="form-label small mb-1" for="cf_montant">Montant</label><input name="montant" id="cf_montant" data-montant inputmode="numeric" class="form-control form-control-sm text-end" required></div>
                            <div class="col-6 col-sm-2"><label class="form-label small mb-1" for="cf_jour">Le (jour)</label><input type="number" name="jour" id="cf_jour" min="1" max="28" value="{{ old('jour', 5) }}" class="form-control form-control-sm @error('jour') is-invalid @enderror" required></div>
                            <div class="col-6 col-sm-2"><label class="form-label small mb-1" for="cf_categorie">Catégorie</label>
                                <select name="categorie" id="cf_categorie" class="form-select form-select-sm"><option value="">Choisir…</option>@foreach ($prevues as $cat)<option>{{ $cat }}</option>@endforeach</select></div>
                            <div class="col-6 col-sm-2"><label class="form-label small mb-1" for="cf_mode">Payée par</label>
                                <select name="mode" id="cf_mode" class="form-select form-select-sm">@foreach (config('gestion.modes_paiement') as $k => $lib)<option value="{{ $k }}">{{ $lib }}</option>@endforeach</select></div>
                            <div class="col-12"><button class="btn btn-sm btn-outline-primary">Ajouter la charge fixe</button></div>
                            @error('jour')<div class="col-12 small text-danger">{{ $message }}</div>@enderror
                        </form>
                        @foreach ($charges as $c)
                            @php($ch = $c['charge'])
                            <form method="post" action="{{ route('charges-fixes.update', $ch) }}" class="row g-2 align-items-center border-top pt-2 mt-2">@csrf @method('put')
                                <div class="col-sm-4"><input name="motif" value="{{ $ch->motif }}" class="form-control form-control-sm" aria-label="Motif" required></div>
                                <div class="col-4 col-sm-2"><input name="montant" data-montant inputmode="numeric" value="{{ number_format($ch->montant, 0, ',', ' ') }}" class="form-control form-control-sm text-end" aria-label="Montant" required></div>
                                <div class="col-3 col-sm-1"><input type="number" name="jour" min="1" max="28" value="{{ $ch->jour }}" class="form-control form-control-sm" aria-label="Jour du mois" required></div>
                                <input type="hidden" name="categorie" value="{{ $ch->categorie }}"><input type="hidden" name="mode" value="{{ $ch->mode }}">
                                <div class="col-5 col-sm-5 d-flex gap-1 justify-content-end"><button class="btn btn-sm btn-light">Enregistrer</button>
                                    <button form="arreter-{{ $ch->id }}" class="btn btn-sm btn-light text-danger">Arrêter</button></div>
                            </form>
                            <form method="post" action="{{ route('charges-fixes.destroy', $ch) }}" id="arreter-{{ $ch->id }}" data-confirmer="Ne plus rappeler « {{ $ch->motif }} » ? Les paiements déjà faits restent dans les dépenses.">@csrf @method('delete')</form>
                        @endforeach
                    </div>
                </details>
            </div>
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
