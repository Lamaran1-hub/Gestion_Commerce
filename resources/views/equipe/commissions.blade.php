@extends('layouts.app')
@section('titre', 'Commissions des vendeurs')
@section('contenu')
    <div class="entete-page">
        <div><h1>Commissions des vendeurs</h1>
            <div class="text-doux">Calculées sur le chiffre d'affaires hors taxes du mois. Chaque versement est enregistré en dépense « Salaires ».</div></div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('commissions.export', ['mois' => $mois->format('Y-m'), 'format' => 'excel']) }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
            <a href="{{ route('commissions.export', ['mois' => $mois->format('Y-m'), 'format' => 'pdf']) }}" target="_blank" class="btn btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        </div>
    </div>

    <form class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <label for="mois" class="form-label mb-0">Mois</label>
        <select name="mois" id="mois" class="form-select" style="width:auto" onchange="this.form.submit()">
            @foreach ($choix as $m)
                <option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $mois->format('Y-m'))>{{ ucfirst($m->translatedFormat('F Y')) }}{{ $m->isCurrentMonth() ? ' (en cours)' : '' }}</option>
            @endforeach
        </select>
    </form>

    @unless ($termine)
        <div class="alert alert-info py-2 small"><i class="bi bi-hourglass-split me-1"></i>Mois en cours : montants <strong>provisoires</strong>. Ils seront payables à partir du 1er {{ $mois->copy()->addMonthNoOverflow()->translatedFormat('F') }}.</div>
    @endunless

    <div class="bloc mb-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Vendeur</th><th class="text-end">Ventes</th><th class="text-end">CA hors taxes</th><th class="text-end">Taux</th><th class="text-end">Commission</th><th>Versement</th></tr></thead>
                <tbody>
                @forelse ($releve as $l)
                    <tr>
                        <td class="text-nowrap fw-semibold">{{ $l['user']->nomComplet() }}</td>
                        <td class="text-end">{{ $l['nb_ventes'] }}</td>
                        <td class="text-end montant text-nowrap">{{ gnf($l['ca_ht']) }}</td>
                        <td class="text-end text-nowrap">{{ rtrim(rtrim(number_format($l['taux'], 2, ',', ''), '0'), ',') }} %</td>
                        <td class="text-end montant fw-semibold text-nowrap">{{ gnf($l['montant']) }}</td>
                        <td style="min-width:240px">
                            @if ($v = $l['versement'])
                                <span class="etat etat-ok"><i class="bi bi-check2"></i> Versée</span>
                                <span class="small text-doux">le {{ $v->created_at->format('d/m/Y') }} · {{ libelle_mode($v->mode) }}{{ $v->reference ? ' · '.$v->reference : '' }} · par {{ $v->auteur?->prenom }}</span>
                                @if (auth()->user()->role?->systeme)
                                    <form method="post" action="{{ route('commissions.annuler', $v) }}" class="d-inline"
                                          data-confirmer="Annuler ce versement ? La dépense « Salaires » correspondante sera supprimée." data-confirmer-type="danger">
                                        @csrf @method('delete')<button class="btn btn-sm btn-link text-danger p-0 ms-1">Annuler</button></form>
                                @endif
                            @elseif (! $termine)
                                <span class="small text-doux">Provisoire</span>
                            @elseif ($l['montant'] > 0)
                                <form method="post" action="{{ route('commissions.verser') }}" class="d-flex flex-wrap gap-1"
                                      data-confirmer="Verser {{ gnf($l['montant']) }} à {{ $l['user']->nomComplet() }} ? Le montant sera enregistré en dépense." data-confirmer-titre="Versement de commission" data-confirmer-bouton="Oui, verser">
                                    @csrf
                                    <input type="hidden" name="user_id" value="{{ $l['user']->id }}"><input type="hidden" name="mois" value="{{ $mois->format('Y-m') }}">
                                    <select name="mode" class="form-select form-select-sm" style="width:auto" aria-label="Mode de versement">
                                        @foreach (config('gestion.modes_paiement') as $k => $lib)<option value="{{ $k }}">{{ $lib }}</option>@endforeach</select>
                                    <input name="reference" class="form-control form-control-sm" style="width:110px" placeholder="Réf. (option)" aria-label="Référence">
                                    <button class="btn btn-sm btn-success">Verser</button>
                                </form>
                            @else
                                <span class="small text-doux">Rien à verser</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="vide"><i class="bi bi-percent"></i>Aucun vendeur n'a de commission.
                        <a href="{{ route('utilisateurs.index') }}">Fixer une commission dans la fiche d'un vendeur</a>.</td></tr>
                @endforelse
                </tbody>
                @if ($releve->isNotEmpty())
                    <tfoot><tr class="fw-semibold"><td colspan="4" class="text-end">Total du mois</td><td class="text-end montant text-nowrap">{{ gnf($releve->sum('montant')) }}</td>
                        <td class="small">{{ gnf($releve->filter(fn ($l) => $l['versement'])->sum('montant')) }} versés
                            @if ($termine && ($reste = $releve->reject(fn ($l) => $l['versement'])->sum('montant')))<span class="text-danger"> · {{ gnf($reste) }} à verser</span>@endif</td></tr></tfoot>
                @endif
            </table>
        </div>
    </div>

    <div class="bloc">
        <div class="bloc-entete"><h2 class="mb-0">Historique des versements (12 mois)</h2></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Mois</th><th class="text-end">Versements</th><th class="text-end">Montant versé</th><th></th></tr></thead>
                <tbody>
                @foreach ($choix as $m)
                    @php($h = $versees->get($m->format('Y-m')))
                    <tr><td>{{ ucfirst($m->translatedFormat('F Y')) }}</td><td class="text-end">{{ $h->nb ?? 0 }}</td>
                        <td class="text-end montant">{{ $h ? gnf($h->total) : '—' }}</td>
                        <td class="text-end"><a href="{{ route('commissions.index', ['mois' => $m->format('Y-m')]) }}" class="small">Détail</a></td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
