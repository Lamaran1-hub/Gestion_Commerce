{{-- Ajustement du prix de vente après une hausse du prix d'achat (bloc « Prix d'achat en hausse ») --}}
@can('produits.gerer')
    <form method="post" action="{{ route('produits.prix-vente', $h['produit']) }}" class="d-inline-flex gap-1 justify-content-end"
          data-confirmer="Changer le prix de vente de « {{ $h['produit']->designation }} » ? Il s'appliquera tout de suite à la caisse." data-confirmer-titre="Nouveau prix de vente" data-confirmer-bouton="Oui, changer le prix">@csrf @method('patch')
        <input name="prix_vente" data-montant inputmode="numeric" value="{{ number_format($h['deja_ajuste'] ? $h['prix'] : max($h['prix'], $h['suggere']), 0, ',', ' ') }}"
               class="form-control form-control-sm text-end" style="width:8.5rem" aria-label="Nouveau prix de vente de {{ $h['produit']->designation }}">
        <button class="btn btn-sm btn-outline-primary" title="Enregistrer ce prix">OK</button></form>
    <div class="small text-doux mt-1">actuel {{ gnf($h['prix']) }}@unless ($h['deja_ajuste']) · suggéré {{ gnf($h['suggere']) }}@endunless</div>
@else{{ gnf($h['prix']) }}@endcan
