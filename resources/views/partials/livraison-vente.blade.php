{{-- Livraison chez le client : programmer, départ, réception, bon de livraison --}}
@php
    $tel = $vente->client?->telephone;
    $messageLivraison = $vente->livraison === 'en_route'
        ? 'Bonjour '.($vente->client?->nomComplet() ?? '').', votre commande '.$vente->numero.' est en route'.($vente->livreur ? ' avec '.$vente->livreur : '').'.'
            .($vente->resteAPayer() ? ' Montant à régler à la livraison : '.gnf($vente->resteAPayer()).'.' : '').' '.boutique()->nom
        : 'Bonjour '.($vente->client?->nomComplet() ?? '').', votre commande '.$vente->numero.' sera livrée'
            .($vente->livraison_prevue_le ? ' le '.$vente->livraison_prevue_le->format('d/m/Y') : ' prochainement').' à : '.$vente->livraison_adresse.'. '.boutique()->nom;
@endphp
<div class="bloc mt-3" id="livraison">
    <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-truck me-1"></i>Livraison</h2>
        @if ($vente->livraison)
            <span class="etat {{ $vente->livraison === 'livree' ? 'etat-ok' : ($vente->livraisonEnRetard() ? 'etat-rupture' : 'etat-alerte') }}">{{ $vente->libelleLivraison() }}{{ $vente->livraisonEnRetard() ? ' (en retard)' : '' }}</span>
        @endif</div>
    <div class="bloc-corps">
        @if ($vente->livraison)
            <div class="small">
                <div><i class="bi bi-geo-alt"></i> {{ $vente->livraison_adresse }}</div>
                @if ($vente->livraison_contact)<div><i class="bi bi-person"></i> {{ $vente->livraison_contact }}</div>@endif
                @if ($vente->livraison_prevue_le)<div><i class="bi bi-calendar-event"></i> Prévue le {{ $vente->livraison_prevue_le->format('d/m/Y') }}</div>@endif
                @if ($vente->livreur)<div><i class="bi bi-bicycle"></i> Livreur : {{ $vente->livreur }}</div>@endif
                @if ($vente->livraison === 'livree')<div class="text-success fw-semibold mt-1"><i class="bi bi-check2-circle"></i> Livrée le {{ $vente->livree_le->format('d/m/Y à H:i') }}, reçue par {{ $vente->livree_a }}</div>@endif
                @if ($vente->livraison !== 'livree' && $vente->resteAPayer())<div class="text-danger fw-semibold mt-1">À encaisser à la livraison : {{ gnf($vente->resteAPayer()) }}</div>@endif
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <a href="{{ route('livraisons.bon', $vente) }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-text me-1"></i>Bon de livraison</a>
                @if ($vente->livraison !== 'livree' && $tel && ($wa = lien_whatsapp($tel, $messageLivraison)))
                    <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn btn-sm btn-success"><i class="bi bi-whatsapp me-1"></i>Prévenir le client</a>
                @endif
            </div>
        @endif

        @if ($vente->statut === 'validee' && $vente->livraison !== 'livree')
            @can('ventes.creer')
                @if ($vente->livraison === 'a_livrer')
                    <form method="post" action="{{ route('livraisons.partir', $vente) }}" class="row g-2 mt-2 border-top pt-2" data-sans-confirmation>@csrf
                        <div class="col-7"><input name="livreur" value="{{ old('livreur', $vente->livreur) }}" class="form-control form-control-sm" placeholder="Nom du livreur" aria-label="Livreur" required maxlength="120"></div>
                        <div class="col-5 d-grid"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-truck"></i> Départ</button></div>
                    </form>
                @endif
                @if ($vente->livraison)
                    <form method="post" action="{{ route('livraisons.livrer', $vente) }}" class="row g-2 mt-2" id="formLivree"
                          data-confirmer="Confirmer la livraison de {{ $vente->numero }} ?{{ $vente->resteAPayer() ? ' Il reste '.gnf($vente->resteAPayer()).' à encaisser.' : '' }}" data-confirmer-titre="Marchandise livrée" data-confirmer-bouton="Oui, livrée">@csrf
                        <div class="col-7"><input name="livree_a" class="form-control form-control-sm" placeholder="Reçue par (nom)" aria-label="Reçue par" required maxlength="120"
                                                  value="{{ old('livree_a') }}"></div>
                        <div class="col-5 d-grid"><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Livrée</button></div>
                    </form>
                @endif
                <details class="mt-2" @if ($errors->hasAny(['livraison_adresse', 'livraison_prevue_le'])) open @endif>
                    <summary class="small text-primary" style="cursor:pointer">{{ $vente->livraison ? 'Modifier l\'adresse ou la date' : 'Livrer cette vente chez le client' }}</summary>
                    <form method="post" action="{{ route('livraisons.programmer', $vente) }}" class="row g-2 mt-1" id="formLivraison" data-sans-confirmation>@csrf
                        <div class="col-12"><input name="livraison_adresse" value="{{ old('livraison_adresse', $vente->livraison_adresse ?? $vente->client?->residence()) }}" required maxlength="255"
                                                   class="form-control form-control-sm @error('livraison_adresse') is-invalid @enderror" placeholder="Adresse : quartier, rue, repère…" aria-label="Adresse de livraison">
                            @error('livraison_adresse')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-7"><input name="livraison_contact" value="{{ old('livraison_contact', $vente->livraison_contact ?? $vente->client?->telephone) }}" maxlength="120"
                                                  class="form-control form-control-sm" placeholder="Contact sur place (nom, téléphone)" aria-label="Contact"></div>
                        <div class="col-5"><input type="date" name="livraison_prevue_le" value="{{ old('livraison_prevue_le', $vente->livraison_prevue_le?->toDateString() ?? now()->toDateString()) }}"
                                                  class="form-control form-control-sm @error('livraison_prevue_le') is-invalid @enderror" aria-label="Date prévue">
                            @error('livraison_prevue_le')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-12 d-grid"><button class="btn btn-sm btn-outline-primary">{{ $vente->livraison ? 'Enregistrer' : 'Programmer la livraison' }}</button></div>
                    </form>
                    @if ($vente->livraison)
                        <form method="post" action="{{ route('livraisons.retirer', $vente) }}" class="mt-1" data-confirmer="Le client emporte finalement sa marchandise : retirer la livraison ?">@csrf
                            <button class="btn btn-sm btn-link p-0 text-danger">Le client l'emporte finalement</button></form>
                    @endif
                </details>
            @endcan
        @endif
    </div>
</div>
