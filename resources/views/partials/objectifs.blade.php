{{-- Objectif du mois de la boutique et classement de l'équipe (tableau de bord) --}}
@php
    $barre = fn (?int $pct) => $pct === null ? 0 : min(100, $pct);
    $couleur = fn (?int $pct, ?bool $atteindra) => $pct >= 100 ? 'bg-success' : ($atteindra ? 'bg-primary' : 'bg-warning');
@endphp
@if ($objectif['objectif'] || $equipe->isNotEmpty())
    <div class="row g-3 mb-3" id="objectifs">
        <div class="{{ $equipe->isNotEmpty() ? 'col-xl-5' : 'col-12' }}">
            <div class="bloc h-100">
                <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-bullseye me-1"></i>Objectif de {{ now()->translatedFormat('F') }}</h2>
                    @can('parametres.gerer')<a href="{{ route('parametres.edit') }}#objectif_mensuel" class="small">Modifier</a>@endcan</div>
                <div class="bloc-corps">
                    @if ($objectif['objectif'])
                        <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-1">
                            <span class="fs-4 fw-bold montant">{{ gnf($objectif['realise']) }}</span>
                            <span class="text-doux">sur {{ gnf($objectif['objectif']) }}</span>
                        </div>
                        <div class="progress my-2" style="height:12px" role="progressbar" aria-label="Progression de l'objectif" aria-valuenow="{{ $objectif['pct'] }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar {{ $couleur($objectif['pct'], $objectif['atteindra']) }}" style="width: {{ $barre($objectif['pct']) }}%"></div>
                        </div>
                        <div class="fw-semibold">{{ $objectif['pct'] }} % atteint</div>
                        <div class="small text-doux mt-1">
                            @if ($objectif['pct'] >= 100)
                                <span class="text-success fw-semibold">Objectif atteint, bravo !</span>
                            @else
                                Au rythme actuel : <strong class="{{ $objectif['atteindra'] ? 'text-success' : 'text-warning-emphasis' }}">{{ gnf($objectif['projection']) }}</strong> en fin de mois
                                ({{ $objectif['atteindra'] ? 'objectif tenu' : 'en dessous de l\'objectif' }}).
                                @if ($objectif['jours_restants'] > 1)<br>Il faut vendre <strong>{{ gnf($objectif['par_jour']) }}</strong> par jour pendant les {{ $objectif['jours_restants'] }} jours restants (aujourd'hui compris).
                                @elseif ($objectif['jours_restants'] === 1)<br>Il reste <strong>{{ gnf($objectif['par_jour']) }}</strong> à vendre aujourd'hui, dernier jour du mois.@endif
                            @endif
                        </div>
                    @else
                        <p class="text-doux mb-0">Aucun objectif pour la boutique ce mois-ci.
                            @can('parametres.gerer')<a href="{{ route('parametres.edit') }}#objectif_mensuel">Fixer un objectif</a>@endcan</p>
                    @endif
                </div>
            </div>
        </div>
        @if ($equipe->isNotEmpty())
            <div class="col-xl-7">
                <div class="bloc h-100">
                    <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-trophy me-1"></i>L'équipe ce mois-ci</h2>
                        <span class="small d-flex gap-2"><a href="{{ route('commissions.index') }}">Commissions à verser</a><a href="{{ route('utilisateurs.index') }}">Objectifs</a></span></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Vendeur</th><th class="text-end">Ventes</th><th class="text-end">CA TTC</th><th style="min-width:120px">Objectif</th><th class="text-end">Commission</th></tr></thead>
                            <tbody>
                            @foreach ($equipe as $rang => $v)
                                <tr><td class="text-nowrap">@if ($rang === 0 && $v['realise'] > 0)<i class="bi bi-trophy-fill text-warning" title="Meilleur vendeur du mois"></i> @endif{{ $v['user']->nomComplet() }}</td>
                                    <td class="text-end">{{ $v['nb_ventes'] }}</td>
                                    <td class="text-end montant text-nowrap">{{ gnf($v['realise']) }}</td>
                                    <td>@if ($v['objectif'])
                                            <div class="progress" style="height:8px" title="{{ $v['pct'] }} % de {{ gnf($v['objectif']) }}"><div class="progress-bar {{ $couleur($v['pct'], $v['atteindra']) }}" style="width: {{ $barre($v['pct']) }}%"></div></div>
                                            <div class="small text-doux">{{ $v['pct'] }} % de {{ gnf($v['objectif']) }}</div>
                                        @else<span class="small text-doux">—</span>@endif</td>
                                    <td class="text-end montant text-nowrap">{{ $v['commission'] ? gnf($v['commission']) : '—' }}</td></tr>
                            @endforeach
                            </tbody>
                            @if ($equipe->sum('commission'))
                                <tfoot><tr class="fw-semibold"><td colspan="4" class="text-end">Commissions à verser</td><td class="text-end montant text-nowrap">{{ gnf($equipe->sum('commission')) }}</td></tr></tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endif
