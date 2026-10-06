@extends('layouts.app')
@section('titre', 'Dettes fournisseurs')
@section('contenu')
    <div class="entete-page">
        <div><h1>Dettes fournisseurs</h1>
            <div class="text-doux">Total dû : <strong class="montant">{{ gnf($total) }}</strong>
                @if ($totalRetard) · <strong class="text-danger montant">{{ gnf($totalRetard) }} en retard</strong>@endif</div></div>
    </div>
    @if ($avoirs->isNotEmpty())
        <div class="alert alert-info"><i class="bi bi-arrow-return-left me-1"></i><strong>Avoirs chez vos fournisseurs</strong> (après des retours de marchandise déjà payée) :
            {{ $fournisseursAvoir->map(fn ($f) => $f->nom.' '.gnf($avoirs[$f->id]))->implode(' · ') }}.
            Choisissez « Avoir fournisseur » comme moyen de règlement pour l'utiliser.</div>
    @endif
    <div class="vstack gap-3">
        @forelse ($dettes as $d)
            <div class="bloc">
                <div class="bloc-entete flex-wrap">
                    <div><h2 class="mb-0">{{ $d['fournisseur']?->nom }}</h2>
                        <div class="small text-doux">{{ collect([$d['fournisseur']?->contact, $d['fournisseur']?->telephone])->filter()->implode(' · ') }}</div></div>
                    <div class="text-end"><div class="fs-5 fw-bold montant">{{ gnf($d['du']) }}</div>
                        @if ($d['en_retard'])<span class="etat etat-rupture">{{ gnf($d['en_retard']) }} en retard</span>
                        @elseif ($d['prochaine_echeance'])<span class="small text-doux">prochaine échéance {{ $d['prochaine_echeance']->format('d/m/Y') }}</span>@endif</div>
                </div>
                <div class="row g-0">
                    <div class="col-lg-7"><table class="table table-sm mb-0"><tbody>
                        @foreach ($d['receptions'] as $a)
                            <tr class="{{ $a->enRetard() ? 'table-danger' : '' }}"><td><a href="{{ route('approvisionnements.show', $a) }}">{{ $a->numero }}</a>
                                    <div class="small text-doux">reçu le {{ $a->date_appro->format('d/m/Y') }}</div></td>
                                <td class="small">{{ $a->echeance ? 'échéance '.$a->echeance->format('d/m/Y') : '—' }}</td>
                                <td class="text-end small">{{ gnf($a->total) }}<div class="text-doux">payé {{ gnf($a->montant_paye) }}</div>
                                    @if ($a->montant_retourne)<div class="text-doux">retourné {{ gnf($a->montant_retourne) }}</div>@endif</td>
                                <td class="text-end fw-semibold montant">{{ gnf($a->resteAPayer()) }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                    <div class="col-lg-5 border-start">
                        <form method="post" action="{{ route('fournisseurs.regler', $d['fournisseur']) }}" class="bloc-corps row g-2"
                              data-confirmer="Enregistrer ce règlement à {{ $d['fournisseur']?->nom }} ? Il sera imputé sur les réceptions les plus anciennes."
                              data-confirmer-titre="Régler le fournisseur" data-confirmer-bouton="Oui, enregistrer">
                            @csrf
                            <div class="col-12"><span class="small fw-semibold">Régler ce fournisseur</span></div>
                            @php($avoirDispo = $avoirs[$d['fournisseur']?->id] ?? 0)
                            <div class="col-6"><input name="montant" data-montant inputmode="numeric" value="{{ number_format($avoirDispo ? min($d['du'], $avoirDispo) : $d['du'], 0, ',', ' ') }}" class="form-control text-end" aria-label="Montant" required></div>
                            <div class="col-6"><select name="mode" class="form-select" aria-label="Mode">
                                @if ($avoirDispo)<option value="{{ \App\Models\PaiementFournisseur::MODE_AVOIR }}">Avoir fournisseur ({{ gnf($avoirDispo) }} disponible)</option>@endif
                                @foreach (config('gestion.modes_paiement') as $k => $lib)<option value="{{ $k }}">{{ $lib }}</option>@endforeach</select></div>
                            <div class="col-12"><input name="reference" class="form-control form-control-sm" placeholder="N° de reçu / transaction (facultatif)" aria-label="Référence"></div>
                            <div class="col-12 d-grid"><button class="btn btn-primary">Enregistrer le règlement</button></div>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="bloc vide"><i class="bi bi-emoji-smile"></i>Aucune dette fournisseur : toutes les réceptions sont réglées.</div>
        @endforelse
    </div>
@endsection
