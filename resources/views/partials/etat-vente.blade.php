@php($etat = $vente->etatPaiement())
<span class="etat etat-{{ $etat }}">{{ ['payee' => 'Payée', 'partielle' => 'Payée en partie', 'credit' => 'À crédit', 'annulee' => 'Annulée'][$etat] }}</span>
