{{-- Pastille d'un moyen de paiement Djomy aux couleurs de l'opérateur ; $code = OM, MOMO, CARD… --}}
@php($fond = \App\Support\MoyensDjomy::couleur($code))
<span class="badge-moyen" style="background:{{ $fond }};color:{{ \App\Models\Boutique::texteSur($fond) }}"
      title="{{ \App\Support\MoyensDjomy::libelle($code) }}" aria-hidden="true">{{ \App\Support\MoyensDjomy::sigle($code) }}</span>
