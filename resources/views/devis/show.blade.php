@extends('layouts.app')
@section('titre', 'Devis '.$d->numero)
@section('contenu')
    <div class="entete-page">
        <div><h1>Devis {{ $d->numero }} <span class="etat {{ $d->classeEtat() }} align-middle">{{ $d->libelleEtat() }}</span>
            @if ($d->origine === 'vitrine')<span class="badge text-bg-success align-middle fs-6"><i class="bi bi-shop-window me-1"></i>Commande vitrine</span>@endif</h1>
            <div class="text-doux">{{ $d->nomClient() }}@if ($d->client_telephone) · <a href="tel:{{ $d->client_telephone }}">{{ $d->client_telephone }}</a>@endif · du {{ $d->date_devis->format('d/m/Y') }}, valable jusqu'au {{ $d->valable_jusqu_au->format('d/m/Y') }}</div></div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('devis.pdf', $d) }}" target="_blank" class="btn btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>Facture proforma</a>
            @php
                $tel = $d->client?->telephone ?? $d->client_telephone;
                $texteWa = $d->origine === 'vitrine' && $d->statut === 'en_cours'
                    ? 'Bonjour '.$d->nomClient().', '.boutique()->nom." a bien reçu votre commande {$d->numero} (".gnf($d->total_ttc).'). Nous vous confirmons la disponibilité : quand souhaitez-vous être livré(e) ou passer la récupérer ?'
                    : "Bonjour, voici votre devis {$d->numero} de ".gnf($d->total_ttc).' ('.boutique()->nom."), valable jusqu'au ".$d->valable_jusqu_au->format('d/m/Y').'.';
            @endphp
            @if ($tel)<a href="tel:{{ $tel }}" class="btn btn-outline-secondary"><i class="bi bi-telephone me-1"></i>Appeler</a>@endif
            @if ($tel && ($wa = lien_whatsapp($tel, $texteWa)))
                <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn btn-success"><i class="bi bi-whatsapp me-1"></i>{{ $d->origine === 'vitrine' && $d->statut === 'en_cours' ? 'Confirmer sur WhatsApp' : 'Envoyer' }}</a>
            @endif
        </div>
    </div>
    @if (session('recu_acompte'))
        <div class="alert alert-success d-flex flex-wrap align-items-center gap-2"><i class="bi bi-receipt"></i>
            <span class="me-auto">Remettez au client son <strong>reçu d'acompte</strong>.</span>
            <a href="{{ session('recu_acompte') }}?imprimer=1" target="_blank" class="btn btn-sm btn-success"><i class="bi bi-printer me-1"></i>Imprimer le reçu</a></div>
    @endif
    @if ($d->statut === 'converti' && $d->vente)
        <div class="alert alert-success">Transformé en vente <a href="{{ route('ventes.show', $d->vente) }}" class="alert-link">{{ $d->vente->numero }}</a>.</div>
    @elseif ($d->estExpire())
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2"><i class="bi bi-clock-history"></i>
            Ce devis a expiré : ses prix ne sont plus garantis.
            @can('ventes.creer')
                <form method="post" action="{{ route('devis.renouveler', $d) }}" class="ms-auto" data-sans-confirmation>@csrf
                    <button class="btn btn-sm btn-warning">Recréer au prix du jour</button></form>
            @endcan</div>
    @endif
    <div class="row g-4">
        <div class="col-lg-8"><div class="bloc">
            <table class="table mb-0">
                <thead><tr><th>Produit</th><th class="text-end">Quantité</th><th class="text-end">Prix unitaire</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                @foreach ($d->lignes as $l)
                    <tr><td>{{ $l->designation }}</td><td class="text-end">{{ qte($l->quantite) }}</td>
                        <td class="text-end montant">{{ gnf($l->prix_unitaire) }}</td><td class="text-end montant">{{ gnf($l->total) }}</td></tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr><td colspan="3" class="text-end text-doux">Sous-total</td><td class="text-end montant">{{ gnf($d->sousTotal()) }}</td></tr>
                    @if ($d->remise)<tr><td colspan="3" class="text-end text-doux">Remise</td><td class="text-end montant">− {{ gnf($d->remise) }}</td></tr>@endif
                    @if ($d->total_tva || collect($d->ventilationTva())->count() > 1)
                        @foreach ($d->ventilationTva() as $v)
                            <tr><td colspan="3" class="text-end text-doux">{{ $v['taux'] > 0 ? $d->libelleTva($v['taux']).' sur '.gnf($v['base']).($d->prix_ttc ? ' HT' : '') : 'Exonéré de TVA (montant HT)' }}</td><td class="text-end montant">{{ $v['taux'] > 0 ? gnf($v['tva']) : gnf($v['base']) }}</td></tr>
                        @endforeach
                    @endif
                    <tr><td colspan="3" class="text-end fw-bold">Total</td><td class="text-end fw-bold montant fs-5">{{ gnf($d->total_ttc) }}</td></tr>
                    @if ($d->acompte)
                        <tr><td colspan="3" class="text-end text-success">Acompte déjà versé</td><td class="text-end montant text-success">− {{ gnf($d->acompte) }}</td></tr>
                        <tr><td colspan="3" class="text-end fw-bold">Reste à payer</td><td class="text-end fw-bold montant">{{ gnf($d->resteAPayer()) }}</td></tr>
                    @endif
                </tfoot>
            </table>
        </div></div>
        <div class="col-lg-4">
            @if ($d->statut === 'en_cours' && ! $d->estExpire())
                @can('ventes.creer')
                    <form method="post" action="{{ route('devis.convertir', $d) }}" class="bloc bloc-corps row g-2"
                          data-confirmer="Transformer le devis {{ $d->numero }} en vente ? Les produits sortiront du stock." data-confirmer-titre="Le client achète" data-confirmer-bouton="Oui, enregistrer la vente">
                        @csrf
                        <div class="col-12"><h2 class="h6 mb-1">Le client achète</h2>
                            <div class="small text-doux">La vente reprend les prix garantis du devis.@if ($d->acompte) L'acompte de {{ gnf($d->acompte) }} est déduit : le client paie <strong>{{ gnf($d->resteAPayer()) }}</strong>.@endif</div></div>
                        <div class="col-6"><label class="form-label small mb-1" for="mode">Paiement</label>
                            <select name="mode" id="mode" class="form-select">
                                @foreach (config('gestion.modes_paiement') as $k => $lib)<option value="{{ $k }}">{{ $lib }}</option>@endforeach</select></div>
                        <div class="col-6"><label class="form-label small mb-1" for="montant_recu">Montant reçu</label>
                            <input name="montant_recu" id="montant_recu" data-montant inputmode="numeric" class="form-control text-end" placeholder="{{ $d->acompte ? '= reste '.gnf($d->resteAPayer(), false) : '= total' }}"></div>
                        <div class="col-12 d-grid"><button class="btn btn-primary"><i class="bi bi-cart-check me-1"></i>Transformer en vente</button></div>
                    </form>
                    @if (! $d->client_id && ! $d->client_nom)
                        <form method="post" action="{{ route('devis.client', $d) }}" class="bloc bloc-corps row g-2 mt-3" id="formClientDevis" data-sans-confirmation>
                            @csrf
                            <div class="col-12"><h2 class="h6 mb-1"><i class="bi bi-person-plus me-1"></i>Indiquer le client</h2>
                                <div class="small text-doux">Nécessaire pour encaisser un acompte et le prévenir à la livraison.</div></div>
                            @if ($clients->isNotEmpty())
                                <div class="col-12"><select name="client_id" class="form-select form-select-sm" aria-label="Client existant">
                                    <option value="">Client existant…</option>
                                    @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->nomComplet() }}{{ $c->telephone ? ' · '.$c->telephone : '' }}</option>@endforeach</select></div>
                                <div class="col-12 small text-doux">ou nouveau :</div>
                            @endif
                            <div class="col-7"><input name="client_nom" class="form-control form-control-sm" placeholder="Nom" aria-label="Nom du client" maxlength="120"></div>
                            <div class="col-5"><input name="client_telephone" type="tel" class="form-control form-control-sm" placeholder="Téléphone" aria-label="Téléphone du client" maxlength="30"></div>
                            <div class="col-12 d-grid"><button class="btn btn-sm btn-outline-primary">Enregistrer le client</button></div>
                        </form>
                    @elseif ($d->resteAPayer() > 0)
                        <form method="post" action="{{ route('devis.acompte', $d) }}" class="bloc bloc-corps row g-2 mt-3" id="formAcompte"
                              data-confirmer="Encaisser cet acompte sur la commande {{ $d->numero }} ?" data-confirmer-titre="Acompte" data-confirmer-bouton="Oui, encaisser">
                            @csrf
                            <div class="col-12"><h2 class="h6 mb-1"><i class="bi bi-wallet2 me-1"></i>Encaisser un acompte</h2>
                                <div class="small text-doux">Le client verse une avance ; elle sera déduite à la livraison.</div></div>
                            <div class="col-6"><label class="form-label small mb-1" for="montant_acompte">Montant</label>
                                <input name="montant" id="montant_acompte" data-montant inputmode="numeric" class="form-control text-end" required
                                       value="{{ old('montant') }}" placeholder="max {{ gnf($d->resteAPayer(), false) }}"></div>
                            <div class="col-6"><label class="form-label small mb-1" for="mode_acompte">Moyen</label>
                                <select name="mode" id="mode_acompte" class="form-select">
                                    @foreach (config('gestion.modes_paiement') as $k => $lib)<option value="{{ $k }}" @selected(old('mode') === $k)>{{ $lib }}</option>@endforeach</select></div>
                            <div class="col-12"><input name="reference" class="form-control form-control-sm" placeholder="N° de transaction (facultatif)" aria-label="Référence"></div>
                            <div class="col-12 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-wallet2 me-1"></i>Encaisser l'acompte</button></div>
                        </form>
                    @endif
                @endcan
            @endif
            @if ($d->statut === 'en_cours')
                @can('ventes.creer')
                    <form method="post" action="{{ route('devis.annuler', $d) }}" class="mt-2 {{ $d->acompte ? 'bloc bloc-corps' : '' }}"
                          data-confirmer="Annuler le devis {{ $d->numero }} ?{{ $d->acompte ? ' L\'acompte de '.gnf($d->acompte).' sera remboursé au client.' : '' }}">@csrf
                        @if ($d->acompte)
                            <label class="form-label small mb-1" for="mode_remboursement">Si la commande est annulée, rembourser l'acompte en</label>
                            <select name="mode_remboursement" id="mode_remboursement" class="form-select form-select-sm mb-2">
                                @foreach (config('gestion.modes_paiement') as $k => $lib)<option value="{{ $k }}">{{ $lib }}</option>@endforeach</select>
                        @endif
                        <button class="btn btn-sm btn-link text-danger p-0">Annuler ce devis{{ $d->acompte ? ' et rembourser '.gnf($d->acompte) : '' }}</button></form>
                @endcan
            @endif
            @if ($d->acomptes->isNotEmpty())
                <div class="bloc mt-3">
                    <div class="bloc-entete"><h2 class="mb-0 h6">Acomptes</h2><span class="fw-semibold montant">{{ gnf($d->acompte) }}</span></div>
                    <table class="table table-sm mb-0"><tbody>
                        @foreach ($d->acomptes as $a)
                            <tr><td class="small">{{ $a->date_versement->format('d/m/Y H:i') }}<div class="text-doux">{{ libelle_mode($a->mode) }}{{ $a->reference && $a->montant > 0 ? ' · '.$a->reference : '' }} · {{ $a->auteur?->nomComplet() }}</div></td>
                                <td class="text-end"><span class="montant {{ $a->montant < 0 ? 'text-danger' : '' }}">{{ $a->montant < 0 ? '− '.gnf(-$a->montant).' rendu' : gnf($a->montant) }}</span>
                                    <div><a href="{{ route('acomptes.recu', $a) }}?imprimer=1" target="_blank" class="small"><i class="bi bi-printer"></i> Reçu</a></div></td></tr>
                        @endforeach
                    </tbody></table>
                </div>
            @endif
        </div>
    </div>
@endsection
