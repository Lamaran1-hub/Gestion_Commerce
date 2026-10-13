{{-- État de pointage d'un paiement électronique, avec ses actions --}}
@if ($p->pointe_le)
    <span class="text-success"><i class="bi bi-check2-circle me-1"></i>Pointé le {{ $p->pointe_le->format('d/m') }}{{ $p->pointeur ? ' par '.$p->pointeur->nomComplet() : '' }}</span>
    <form method="post" action="{{ route('pointage.annuler', $p) }}" class="d-inline" data-confirmer="Ce paiement redeviendra « à pointer ». Continuer ?" data-confirmer-bouton="Oui, annuler le pointage">
        @csrf <button class="btn btn-link btn-sm p-0 ms-1 align-baseline">Annuler</button></form>
@elseif ($p->litige)
    <span class="text-danger fw-semibold"><i class="bi bi-exclamation-octagon me-1"></i>Litige : {{ $p->litige }}</span>
    <form method="post" action="{{ route('pointage.annuler', $p) }}" class="d-inline" data-confirmer="Le paiement a été retrouvé ? Il redeviendra « à pointer »." data-confirmer-bouton="Oui">
        @csrf <button class="btn btn-link btn-sm p-0 ms-1 align-baseline">Retrouvé</button></form>
@else
    <details class="pointage-litige">
        <summary class="text-danger">Introuvable ?</summary>
        <form method="post" action="{{ route('pointage.litige', $p) }}" class="d-flex flex-wrap gap-2 mt-1">
            @csrf
            <select name="note" class="form-select form-select-sm w-auto" required data-autre aria-label="Pourquoi ce paiement est introuvable">
                <option value="">Pourquoi ?</option>
                @foreach (['Absent des SMS / du relevé', 'Montant différent', 'Numéro de transaction inconnu', 'Autre'] as $n)<option>{{ $n }}</option>@endforeach
            </select>
            <button class="btn btn-sm btn-outline-danger">Mettre en litige</button>
        </form>
    </details>
@endif
