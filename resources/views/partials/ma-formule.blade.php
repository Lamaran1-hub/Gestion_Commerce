{{-- Consommation de la formule, fonctions incluses et comparatif des formules (page Abonnement) --}}
@php
    $toutesFormules = \App\Models\Plan::where('actif', true)->orderBy('prix_mensuel')->get();
    $fonctions = config('gestion.fonctions');
@endphp
<div class="row g-3 mt-1">
    <div class="col-lg-5">
        <div class="bloc h-100">
            <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-speedometer me-1"></i>Ma formule : utilisation</h2></div>
            <div class="bloc-corps">
                @foreach (config('gestion.limites') as $cle => [, $libelle])
                    @php
                        $usage = $b->usage($cle);
                        $max = $b->limite($cle);
                        $pct = $max ? min(100, (int) round($usage * 100 / $max)) : 0;
                    @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small"><span>{{ $libelle }}</span>
                            <strong class="{{ $max && $usage >= $max ? 'text-danger' : '' }}">{{ $usage }} / {{ $max ?? 'illimité' }}</strong></div>
                        @if ($max)
                            <div class="progress" style="height:8px" role="progressbar" aria-label="{{ $libelle }}" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar {{ $pct >= 100 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-success') }}" style="width: {{ $pct }}%"></div></div>
                            @if ($pct >= 80)<div class="small {{ $pct >= 100 ? 'text-danger' : 'text-warning-emphasis' }} mt-1">{{ $pct >= 100 ? 'Limite atteinte' : 'Bientôt atteinte' }} : pensez à la formule supérieure.</div>@endif
                        @endif
                    </div>
                @endforeach
                <div class="small fw-semibold mb-1">Fonctions</div>
                <ul class="list-unstyled small mb-0">
                    @foreach ($fonctions as $cle => [$libelle, $icone])
                        <li class="{{ $b->aFonction($cle) ? '' : 'text-doux' }}"><i class="bi bi-{{ $b->aFonction($cle) ? 'check-circle-fill text-success' : 'lock' }} me-1"></i>{{ $libelle }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="bloc h-100">
            <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-columns-gap me-1"></i>Comparer les formules</h2></div>
            <div class="table-responsive"><table class="table table-sm mb-0 align-middle text-center">
                <thead><tr><th class="text-start"></th>
                    @foreach ($toutesFormules as $f)<th class="{{ $f->id === $b->plan_id ? 'table-success' : '' }}">{{ $f->nom }}<div class="small fw-normal montant">{{ gnf($f->prix_mensuel) }}/mois</div>
                        @if ($f->id === $b->plan_id)<span class="etat etat-ok">Actuelle</span>@endif</th>@endforeach</tr></thead>
                <tbody>
                    @foreach (config('gestion.limites') as $cle => [$colonne, $libelle])
                        <tr><td class="text-start small">{{ $libelle }}</td>
                            @foreach ($toutesFormules as $f)<td class="small">{{ $f->{$colonne} ?? 'Illimité' }}</td>@endforeach</tr>
                    @endforeach
                    @foreach ($fonctions as $cle => [$libelle])
                        <tr><td class="text-start small">{{ $libelle }}</td>
                            @foreach ($toutesFormules as $f)<td>{!! $f->inclut($cle) ? '<i class="bi bi-check-lg text-success" aria-label="incluse"></i>' : '<span class="text-doux" aria-label="non incluse">—</span>' !!}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    </div>
</div>
