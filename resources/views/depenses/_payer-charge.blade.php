{{-- Paiement en un clic d'une charge fixe du mois (page Dépenses, bloc « Charges fixes ») --}}
<form method="post" action="{{ route('depenses.store') }}" class="d-inline-flex flex-wrap gap-1 justify-content-end"
      data-confirmer="Enregistrer le paiement de « {{ $ch->motif }} » ?" data-confirmer-titre="Payer une charge fixe" data-confirmer-bouton="Oui, payer">@csrf
    <input type="hidden" name="charge_fixe_id" value="{{ $ch->id }}">
    <input type="hidden" name="motif" value="{{ $ch->motif }} — {{ now()->translatedFormat('F Y') }}">
    <input type="hidden" name="categorie" value="{{ $ch->categorie }}">
    <input type="hidden" name="date_depense" value="{{ now()->toDateString() }}">
    <input name="montant" data-montant inputmode="numeric" value="{{ number_format($ch->montant, 0, ',', ' ') }}" class="form-control form-control-sm text-end" style="width:7.5rem" aria-label="Montant payé">
    <select name="mode" class="form-select form-select-sm" style="width:auto" aria-label="Payée par">
        @foreach (config('gestion.modes_paiement') as $k => $lib)<option value="{{ $k }}" @selected($ch->mode === $k)>{{ $lib }}</option>@endforeach
    </select>
    <button class="btn btn-sm btn-primary">Payer</button></form>
