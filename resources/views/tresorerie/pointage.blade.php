@extends('layouts.app')
@section('titre', 'Pointage mobile money')
@section('contenu')
    <div class="entete-page">
        <div><h1>Pointage mobile money</h1>
            <div class="text-doux">Cochez les paiements retrouvés sur vos SMS ou votre relevé (mobile money, carte, virement, chèque) : l'argent n'est pas dans le tiroir.</div></div>
    </div>

    <form class="bloc bloc-corps row g-2 align-items-end mb-3">
        @include('partials.periode')
        <div class="col-6 col-md-auto"><label class="form-label small text-doux mb-1" for="mode">Moyen</label>
            <select name="mode" id="mode" class="form-select form-select-sm"><option value="">Tous</option>
                @foreach (\App\Services\PointagePaiements::MODES as $m)<option value="{{ $m }}" @selected($mode === $m)>{{ libelle_mode($m) }}</option>@endforeach</select></div>
        <div class="col-6 col-md-auto"><label class="form-label small text-doux mb-1" for="etat">État</label>
            <select name="etat" id="etat" class="form-select form-select-sm">
                @foreach (['a_pointer' => 'À pointer', 'pointes' => 'Pointés', 'litiges' => 'En litige', 'tous' => 'Tous'] as $k => $lib)<option value="{{ $k }}" @selected($etat === $k)>{{ $lib }}</option>@endforeach</select></div>
        <div class="col-md-auto"><button class="btn btn-sm btn-primary">Filtrer</button></div>
    </form>

    @if ($resume->isEmpty())
        <div class="bloc bloc-corps text-doux">Aucun paiement électronique du {{ \Carbon\Carbon::parse($du)->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($au)->format('d/m/Y') }}.</div>
    @else
        <div class="row g-2 mb-3" id="resumePointage">
            @foreach ($resume as $r)
                <div class="col-6 col-lg-3">
                    <div class="bloc kpi h-100">
                        <div class="etiquette">{{ libelle_mode($r['mode']) }}</div>
                        <div class="valeur montant">{{ gnf($r['encaisse']) }}</div>
                        <div class="small"><span class="text-success">Pointé {{ gnf($r['pointe']) }}</span>
                            @if ($r['nb_a_pointer'])<div class="text-doux">À pointer : {{ $r['nb_a_pointer'] }} ({{ gnf($r['a_pointer']) }})</div>@endif
                            @if ($r['nb_litiges'])<div class="text-danger fw-semibold">Litiges : {{ $r['nb_litiges'] }} ({{ gnf($r['litiges']) }})</div>@endif</div>
                    </div>
                </div>
            @endforeach
        </div>

        <form method="post" action="{{ route('pointage.pointer') }}" id="formPointage">@csrf</form>
        <div class="bloc">
            @if ($paiements->isEmpty())
                <div class="bloc-corps text-doux">{{ $etat === 'a_pointer' ? 'Tout est pointé sur cette période.' : 'Aucun paiement dans cet état.' }}</div>
            @else
                <div class="table-responsive"><table class="table mb-0 align-middle" id="listePointage">
                    <thead><tr>
                        <th class="ps-3" style="width:2rem">@if ($etat === 'a_pointer')<input type="checkbox" class="form-check-input" id="toutCocher" aria-label="Tout cocher">@endif</th>
                        <th>Paiement</th><th class="d-none d-md-table-cell">Caissier</th><th class="d-none d-sm-table-cell">Référence</th><th class="text-end">Montant</th><th class="d-none d-lg-table-cell">État</th></tr></thead>
                    <tbody>
                    @foreach ($paiements as $p)
                        <tr class="{{ $p->litige ? 'table-danger' : '' }}" data-paiement="{{ $p->id }}" data-somme="{{ $p->montant }}">
                            <td class="ps-3">@if (! $p->pointe_le && ! $p->litige)
                                <input type="checkbox" class="form-check-input coche-pointage" name="paiements[]" value="{{ $p->id }}" form="formPointage" aria-label="Pointer ce paiement">@endif</td>
                            <td><div class="fw-semibold">{{ $p->libelleMode() }}
                                    <span class="d-sm-none fw-normal small ms-1">@if ($p->reference)n° {{ $p->reference }}@else<span class="etat etat-alerte">Sans n°</span>@endif</span></div>
                                <div class="small text-doux">{{ $p->date_paiement->format('d/m H:i') }}
                                    @if ($p->vente) · <a href="{{ route('ventes.show', $p->vente) }}">{{ $p->vente->numero }}</a>@endif
                                    @if ($p->client) · {{ $p->client->nomComplet() }}@endif
                                    <span class="d-md-none"> · {{ $p->caissier?->nomComplet() }}</span></div></td>
                            <td class="d-none d-md-table-cell small">{{ $p->caissier?->nomComplet() ?? '—' }}</td>
                            <td class="small text-break d-none d-sm-table-cell">@if ($p->reference){{ $p->reference }}@else<span class="etat etat-alerte text-nowrap">Sans n°</span>@endif</td>
                            <td class="text-end montant text-nowrap {{ $p->montant < 0 ? 'text-danger' : '' }}">{{ $p->montant < 0 ? '− '.gnf(-$p->montant) : gnf($p->montant) }}</td>
                            <td class="d-none d-lg-table-cell small">@include('tresorerie.pointage-etat')</td>
                        </tr>
                        <tr class="d-lg-none {{ $p->litige ? 'table-danger' : '' }}"><td></td><td colspan="4" class="small pt-0 border-top-0">@include('tresorerie.pointage-etat')</td></tr>
                    @endforeach
                    </tbody>
                </table></div>
                @if ($etat === 'a_pointer')
                    <div class="bloc-corps border-top d-flex flex-wrap align-items-center gap-2 position-sticky bottom-0 bg-white">
                        <button type="submit" form="formPointage" class="btn btn-primary" id="boutonPointer" disabled><i class="bi bi-check2-all me-1"></i>Marquer comme reçus <span id="nbCoches"></span></button>
                        <span class="small text-doux">Pas sur le relevé ? « Introuvable » le met en litige.</span>
                    </div>
                @endif
            @endif
        </div>
        <div class="mt-2">{{ $paiements->links() }}</div>
    @endif
@endsection
@push('scripts')
<script>
(() => {
    const coches = [...document.querySelectorAll('.coche-pointage')];
    const bouton = document.getElementById('boutonPointer');
    if (!bouton) return;
    const maj = () => {
        const n = coches.filter(c => c.checked).length;
        bouton.disabled = n === 0;
        document.getElementById('nbCoches').textContent = n ? `(${n} · ${gnf(coches.filter(c => c.checked).reduce((s, c) => s + Number(c.closest('tr').dataset.somme || 0), 0))})` : '';
    };
    coches.forEach(c => c.addEventListener('change', maj));
    document.getElementById('toutCocher')?.addEventListener('change', (e) => { coches.forEach(c => { c.checked = e.target.checked; }); maj(); });
})();
</script>
@endpush
