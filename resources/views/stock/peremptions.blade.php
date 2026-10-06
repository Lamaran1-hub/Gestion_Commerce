@extends('layouts.app')
@section('titre', 'Péremptions')
@section('contenu')
    <div class="entete-page">
        <div><h1>Dates de péremption</h1>
            <div class="text-doux">Selon les dates saisies à la réception. Quantités estimées « premier entré, premier sorti ».</div></div>
        <form class="d-flex align-items-center gap-2">
            <label for="jours" class="small text-doux text-nowrap">Alerter</label>
            <select name="jours" id="jours" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach ([7, 15, 30, 60, 90] as $j)<option value="{{ $j }}" @selected($jours == $j)>{{ $j }} jours avant</option>@endforeach
                @unless (in_array($jours, [7, 15, 30, 60, 90]))<option value="{{ $jours }}" selected>{{ $jours }} jours avant</option>@endunless
            </select>
        </form>
    </div>

    @foreach (['perimes' => ['Déjà périmés : à retirer de la vente', 'etat-rupture', $perimes], 'bientot' => ['Périment bientôt : à vendre en priorité', 'etat-alerte', $bientot]] as $cle => [$titre, $classe, $lots])
        <div class="bloc mb-3">
            <div class="bloc-entete"><h2 class="mb-0">{{ $titre }}</h2>
                @if ($lots->isNotEmpty())<span class="small text-doux">Valeur d'achat : <strong class="montant">{{ gnf($lots->sum('valeur')) }}</strong></span>@endif</div>
            <div class="table-responsive"><table class="table mb-0 align-middle">
                <thead><tr><th>Produit</th><th>Réception</th><th>Périme le</th><th class="text-end">Quantité estimée</th><th class="text-end">Valeur</th>
                    @if ($cle === 'perimes')<th class="no-print">Retirer du stock</th>@endif</tr></thead>
                <tbody>
                @forelse ($lots as $l)
                    @php($p = $l['produit'])
                    <tr><td><a href="{{ route('produits.show', $p) }}" class="fw-semibold">{{ $p->designation }}</a></td>
                        <td class="small">{{ $l['numero'] }} du {{ \Carbon\Carbon::parse($l['date_appro'])->format('d/m/Y') }}</td>
                        <td><span class="etat {{ $classe }}">{{ $l['date_peremption']->format('d/m/Y') }}</span>
                            <div class="small text-doux">{{ $l['jours'] < 0 ? 'depuis '.abs($l['jours']).' jour(s)' : ($l['jours'] === 0 ? "aujourd'hui" : 'dans '.$l['jours'].' jour(s)') }}</div></td>
                        <td class="text-end">{{ qte($l['quantite']) }} {{ $p->unite }}</td>
                        <td class="text-end montant">{{ gnf($l['valeur']) }}</td>
                        @if ($cle === 'perimes')
                            <td class="no-print">
                                @can('stock.ajuster')
                                    <form method="post" action="{{ route('stock.peremptions.retirer', $p) }}" class="d-flex gap-1"
                                          data-confirmer="Retirer cette marchandise périmée du stock ? La perte sera enregistrée." data-confirmer-titre="Retrait du stock" data-confirmer-bouton="Oui, retirer">
                                        @csrf
                                        <input type="number" step="0.01" min="0.01" max="{{ $p->stock }}" name="quantite" value="{{ $l['quantite'] }}" class="form-control form-control-sm text-end" style="width:100px" aria-label="Quantité à retirer">
                                        <button class="btn btn-sm btn-outline-danger">Retirer</button>
                                    </form>
                                @endcan
                            </td>
                        @endif</tr>
                @empty
                    <tr><td colspan="6" class="vide">{{ $cle === 'perimes' ? 'Aucun produit périmé en stock.' : 'Rien ne périme dans les '.$jours.' prochains jours.' }}</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
    @endforeach
    <p class="small text-doux">Conseil : baissez le prix ou proposez les lots qui périment bientôt en priorité. Pensez à saisir la date de péremption à chaque réception.</p>
@endsection
