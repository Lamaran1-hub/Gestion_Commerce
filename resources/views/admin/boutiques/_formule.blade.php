{{-- Limites et fonctions du client : formule + dérogations accordées par le propriétaire --}}
@php
    $derog = $boutique->derogations ?? [];
    $plan = $boutique->plan;
@endphp
<form method="post" action="{{ route('admin.boutiques.derogations', $boutique) }}" class="bloc mb-3"
      data-confirmer="Enregistrer ces conditions pour ce client ?" data-confirmer-titre="Limites et fonctions" data-confirmer-bouton="Oui, enregistrer">
    @csrf
    <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-sliders me-1"></i>Limites et fonctions de ce client</h2>
        <span class="small text-doux">Formule {{ $plan?->nom ?? '—' }}</span></div>
    <div class="table-responsive"><table class="table mb-0 align-middle">
        <thead><tr><th>Limite</th><th class="text-end">Utilisé</th><th class="text-end">Formule</th><th style="width:190px">Dérogation</th><th class="text-end">Appliqué</th></tr></thead>
        <tbody>
        @foreach (config('gestion.limites') as $cle => [$colonne, $libelle])
            @php
                $usage = $boutique->usage($cle);
                $effectif = $boutique->limite($cle);
                $depasse = $effectif !== null && $usage > $effectif;
            @endphp
            <tr><td>{{ $libelle }}</td>
                <td class="text-end {{ $depasse ? 'text-danger fw-bold' : '' }}">{{ $usage }}</td>
                <td class="text-end">{{ $plan?->{$colonne} ?? 'Illimité' }}</td>
                <td><input type="number" min="0" name="{{ $colonne }}" value="{{ old($colonne, $derog[$colonne] ?? '') }}" class="form-control form-control-sm text-end"
                           placeholder="selon formule" aria-label="Dérogation : {{ $libelle }}"></td>
                <td class="text-end fw-semibold">{{ $effectif ?? 'Illimité' }}@if ($depasse)<div class="small text-danger">dépassé</div>@endif</td></tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="bloc-corps border-top">
        <div class="small text-doux mb-2">Dérogation : vide = selon la formule, 0 = illimité pour ce client. Le nombre de boutiques se règle sur la boutique principale du réseau.</div>
        <span class="form-label d-block">Fonctions</span>
        <div class="row g-1">
            @foreach (config('gestion.fonctions') as $cle => [$libelle, $icone])
                @php($inclus = $plan?->inclut($cle) ?? true)
                <div class="col-md-6"><div class="form-check">
                    @if ($inclus)
                        <input type="checkbox" class="form-check-input" checked disabled id="fd_{{ $cle }}">
                        <label class="form-check-label" for="fd_{{ $cle }}"><i class="bi bi-{{ $icone }} me-1 text-doux"></i>{{ $libelle }} <span class="small text-doux">(formule)</span></label>
                    @else
                        <input type="checkbox" name="fonctions[]" value="{{ $cle }}" class="form-check-input" id="fd_{{ $cle }}" @checked(in_array($cle, old('fonctions', $derog['fonctions'] ?? []), true))>
                        <label class="form-check-label" for="fd_{{ $cle }}"><i class="bi bi-{{ $icone }} me-1 text-doux"></i>{{ $libelle }} <span class="small text-doux">(accorder)</span></label>
                    @endif
                </div></div>
            @endforeach
        </div>
        <div class="row g-2 mt-2 align-items-end">
            <div class="col-md-8"><label class="form-label small" for="motif_derogation">Motif (journal)</label>
                <input name="motif" id="motif_derogation" class="form-control form-control-sm" maxlength="200" placeholder="Ex. : geste commercial, essai de la trésorerie pendant 1 mois"></div>
            <div class="col-md-4 d-grid"><button class="btn btn-primary btn-sm">Enregistrer</button></div>
        </div>
    </div>
</form>
