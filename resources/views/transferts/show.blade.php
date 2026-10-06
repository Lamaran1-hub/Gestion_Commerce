@extends('layouts.app')
@section('titre', 'Transfert '.$t->numero)
@section('contenu')
    <div class="entete-page">
        <div><h1>Transfert {{ $t->numero }} <span class="etat {{ ['envoye' => 'etat-alerte', 'recu' => 'etat-ok'][$t->statut] ?? 'etat-rupture' }} align-middle">{{ $t->libelleStatut() }}</span></h1>
            <div class="text-doux">{{ $t->source->nom }} <i class="bi bi-arrow-right"></i> {{ $t->destination->nom }} · valeur {{ gnf($t->valeur) }}</div></div>
        <div class="d-flex gap-2">
            @if ($t->statut === 'envoye' && ! $estDestination)
                <form method="post" action="{{ route('transferts.annuler', $t) }}"
                      data-confirmer="Annuler ce transfert ? La marchandise revient dans votre stock." data-confirmer-titre="Annuler le transfert" data-confirmer-bouton="Oui, annuler" data-confirmer-type="danger">
                    @csrf <button class="btn btn-outline-danger">Annuler le transfert</button></form>
            @endif
            <a href="{{ route('transferts.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Transferts</a>
        </div>
    </div>
    <div class="small text-doux mb-3">
        Envoyé le {{ $t->envoye_le?->format('d/m/Y à H:i') }} par {{ $t->expediteur?->nomComplet() }}@if ($t->note) — {{ $t->note }}@endif
        @if ($t->recu_le)<br>Reçu le {{ $t->recu_le->format('d/m/Y à H:i') }} par {{ $t->receptionnaire?->nomComplet() }}@if ($t->note_reception) — {{ $t->note_reception }}@endif @endif
    </div>

    @php($aReceptionner = $t->statut === 'envoye' && $estDestination)
    <form method="post" action="{{ route('transferts.recevoir', $t) }}" @if ($aReceptionner) data-confirmer="Confirmer la réception ? Les quantités saisies entrent dans votre stock ; les manques sont enregistrés en perte." data-confirmer-titre="Réception du transfert" data-confirmer-bouton="Oui, réceptionner" @endif>
        @csrf
        <div class="bloc"><div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr><th>Produit</th><th class="text-end">Envoyé</th><th class="text-end" style="width:170px">{{ $aReceptionner ? 'Réellement reçu' : 'Reçu' }}</th><th class="text-end">Coût unitaire</th></tr></thead>
                <tbody>
                @foreach ($t->lignes as $l)
                    <tr><td>{{ $l->designation }}@if ($l->code_barre)<div class="small text-doux">{{ $l->code_barre }}</div>@endif</td>
                        <td class="text-end">{{ qte($l->quantite) }} {{ $l->unite }}</td>
                        <td class="text-end">
                            @if ($aReceptionner)
                                <input type="number" step="0.01" min="0" max="{{ $l->quantite }}" name="recues[{{ $l->id }}]" value="{{ $l->quantite }}" class="form-control form-control-sm text-end" aria-label="Quantité reçue de {{ $l->designation }}">
                            @elseif ($l->quantite_recue !== null)
                                {{ qte($l->quantite_recue) }}@if ($l->ecart() > 0)<div class="small text-danger">manque {{ qte($l->ecart()) }}</div>@endif
                            @else — @endif
                        </td>
                        <td class="text-end montant">{{ gnf($l->cout_unitaire) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div></div>
        @if ($aReceptionner)
            <div class="bloc bloc-corps mt-3">
                <label class="form-label" for="note_reception">Remarque (colis abîmé, carton ouvert…)</label>
                <input name="note_reception" id="note_reception" class="form-control mb-3" maxlength="500">
                <button class="btn btn-primary btn-lg"><i class="bi bi-box-arrow-in-down me-1"></i>Réceptionner</button>
                <div class="form-text">Comptez la marchandise avant de valider : un manque devient une perte en transit.</div>
            </div>
        @elseif ($t->statut === 'envoye')
            <div class="alert alert-info mt-3 mb-0">En route : la boutique « {{ $t->destination->nom }} » doit réceptionner ce transfert.</div>
        @endif
    </form>
@endsection
