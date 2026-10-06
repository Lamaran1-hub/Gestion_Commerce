@switch($c->etat())
    @case('active')<span class="etat etat-ok">Utilisable</span>@if ($c->expire_le)<div class="small text-doux">jusqu'au {{ $c->expire_le->format('d/m/Y') }}</div>@endif @break
    @case('epuisee')<span class="etat etat-neutre">Utilisée</span> @break
    @case('expiree')<span class="etat etat-alerte">Expirée</span><div class="small text-doux">le {{ $c->expire_le->format('d/m/Y') }}</div> @break
    @default<span class="etat etat-rupture">Annulée</span>
@endswitch
