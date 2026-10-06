@extends('layouts.app')
@section('titre', 'Carte cadeau '.$c->codeMasque())
@section('contenu')
    <div class="entete-page">
        <div><a href="{{ route('cartes-cadeaux.index') }}" class="small"><i class="bi bi-arrow-left"></i> Cartes cadeaux</a>
            <h1 class="font-monospace">{{ $c->codeMasque() }}</h1>
            <div class="text-doux">Vendue le {{ $c->created_at->format('d/m/Y à H:i') }} par {{ $c->auteur?->nomComplet() ?? '—' }}</div></div>
        @if ($c->statut !== 'annulee')
            <a href="{{ route('cartes-cadeaux.imprimer', $c) }}" target="_blank" class="btn btn-primary"><i class="bi bi-printer me-1"></i>Imprimer / envoyer</a>
        @endif
    </div>
    @if (session('imprimer_carte'))
        <div class="alert alert-success d-flex flex-wrap align-items-center gap-2"><i class="bi bi-gift"></i>
            <span class="me-auto">Remettez la <strong>carte cadeau</strong> au client : imprimez-la ou envoyez-la par WhatsApp.</span>
            <a href="{{ session('imprimer_carte') }}?imprimer=1" target="_blank" class="btn btn-sm btn-success"><i class="bi bi-printer me-1"></i>Imprimer la carte</a></div>
    @endif
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="bloc bloc-corps">
                <div class="d-flex justify-content-between align-items-start">
                    <div><div class="text-doux small">Solde disponible</div><div class="fs-3 fw-bold montant">{{ gnf($c->solde) }}</div>
                        <div class="small text-doux">sur {{ gnf($c->montant) }} à l'achat</div></div>
                    <div class="text-end">@include('cartes-cadeaux.etat', ['c' => $c])</div>
                </div>
                <dl class="row small mb-0 mt-3">
                    <dt class="col-5 text-doux fw-normal">Acheteur</dt><dd class="col-7">{{ $c->acheteur ?? 'Client de passage' }}@if ($c->client) · <a href="{{ route('clients.show', $c->client) }}">fiche</a>@endif</dd>
                    <dt class="col-5 text-doux fw-normal">Offerte à</dt><dd class="col-7">{{ $c->beneficiaire ?? '—' }}@if ($c->telephone)<div>{{ numero_affiche($c->telephone) }}</div>@endif</dd>
                    @if ($c->message)<dt class="col-5 text-doux fw-normal">Message</dt><dd class="col-7 fst-italic">« {{ $c->message }} »</dd>@endif
                    <dt class="col-5 text-doux fw-normal">Validité</dt><dd class="col-7">{{ $c->expire_le ? 'jusqu\'au '.$c->expire_le->format('d/m/Y') : 'sans limite' }}</dd>
                    @if ($c->statut === 'annulee')
                        <dt class="col-5 text-doux fw-normal">Annulée</dt><dd class="col-7">le {{ $c->annulee_le->format('d/m/Y') }} — {{ $c->motif_annulation }}</dd>
                    @endif
                </dl>
                <div class="form-text">Le code complet n'apparaît que sur la carte imprimée : il suffit pour la dépenser.</div>
            </div>
            @can('ventes.annuler')
                @if ($c->statut !== 'annulee' && $c->solde > 0)
                    <form method="post" action="{{ route('cartes-cadeaux.prolonger', $c) }}" class="bloc bloc-corps mt-3 row g-2 align-items-end" data-sans-confirmation>
                        @csrf
                        <div class="col-7"><label class="form-label small mb-1" for="expire_le">Prolonger jusqu'au</label>
                            <input type="date" name="expire_le" id="expire_le" value="{{ ($c->expire_le && $c->expire_le->isFuture() ? $c->expire_le : now())->copy()->addMonths(6)->toDateString() }}" min="{{ now()->toDateString() }}" class="form-control" required></div>
                        <div class="col-5 d-grid"><button class="btn btn-outline-primary">Prolonger</button></div>
                    </form>
                    <form method="post" action="{{ route('cartes-cadeaux.annuler', $c) }}" class="bloc bloc-corps mt-3"
                          data-confirmer="Annuler cette carte cadeau ?{{ $c->solde ? ' Son solde de '.gnf($c->solde).' sera rendu au porteur.' : '' }}" data-confirmer-titre="Annuler la carte" data-confirmer-bouton="Oui, annuler">
                        @csrf
                        <h2 class="h6 text-danger"><i class="bi bi-x-circle me-1"></i>Annuler la carte</h2>
                        @if ($c->solde)
                            <label class="form-label small mb-1" for="mode_remboursement">Rendre {{ gnf($c->solde) }} en</label>
                            <select name="mode_remboursement" id="mode_remboursement" class="form-select mb-2">
                                @foreach (config('gestion.modes_paiement') as $cle => $libelle)<option value="{{ $cle }}">{{ $libelle }}</option>@endforeach
                            </select>
                        @else
                            <input type="hidden" name="mode_remboursement" value="especes">
                        @endif
                        <input name="motif" class="form-control mb-2" maxlength="255" placeholder="Motif (carte perdue, erreur, client remboursé…)" aria-label="Motif" required>
                        <button class="btn btn-outline-danger w-100">Annuler la carte</button>
                    </form>
                @endif
            @endcan
        </div>
        <div class="col-lg-7">
            <div class="bloc">
                <div class="bloc-corps pb-0"><h2 class="h6">Historique</h2></div>
                <div class="table-responsive"><table class="table mb-0 align-middle">
                    <thead><tr><th class="d-none d-sm-table-cell">Date</th><th>Opération</th><th class="text-end">Montant</th></tr></thead>
                    <tbody>
                    @foreach ($c->mouvements as $m)
                        <tr>
                            <td class="small text-nowrap d-none d-sm-table-cell">{{ $m->date_mouvement->format('d/m/Y H:i') }}</td>
                            <td>{{ $m->libelle() }}
                                @if ($m->vente)<a href="{{ route('ventes.show', $m->vente) }}">{{ $m->vente->numero }}</a>@endif
                                <div class="small text-doux">{{ collect([$m->date_mouvement->format('d/m/Y H:i'), $m->mode ? libelle_mode($m->mode) : null, $m->type === 'recredit' ? $m->motif : null, $m->auteur?->nomComplet()])->filter()->implode(' · ') }}</div></td>
                            <td class="text-end text-nowrap montant {{ $m->montant < 0 ? 'text-danger' : 'text-success' }}">{{ $m->montant < 0 ? '−' : '+' }} {{ gnf(abs($m->montant)) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
@endsection
