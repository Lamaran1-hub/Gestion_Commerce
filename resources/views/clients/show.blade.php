@extends('layouts.app')
@section('titre', $client->nomComplet())
@section('contenu')
    <div class="entete-page">
        <div><h1>{{ $client->nomComplet() }}</h1>
            <div class="text-doux">{{ $client->code }}{{ $client->telephone ? ' · '.$client->telephone : '' }}@if ($client->residence()) · <i class="bi bi-house-door"></i> {{ $client->residence() }}@endif
                @if ($client->date_naissance) · 🎂 {{ $client->date_naissance->translatedFormat('j F') }}@if ($client->joursAvantAnniversaire() === 0) <strong class="text-success">(aujourd'hui !)</strong>@endif @endif</div></div>
        <div class="d-flex flex-wrap gap-2">
            @can('clients.gerer')<a href="{{ route('clients.edit', $client) }}" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Modifier</a>@endcan
            @can('ventes.voir')<a href="{{ route('ventes.index', ['client_id' => $client->id]) }}" class="btn btn-outline-primary">Toutes ses ventes</a>@endcan
            <div class="dropdown">
                <button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-file-earmark-text me-1"></i>Relevé de compte</button>
                <form class="dropdown-menu dropdown-menu-end p-3" style="min-width:260px" action="{{ route('clients.releve', $client) }}" target="_blank" data-sans-confirmation>
                    <label class="form-label small" for="releve_du">Du</label>
                    <input type="date" name="du" id="releve_du" class="form-control form-control-sm mb-2" value="{{ now()->subMonths(3)->startOfMonth()->toDateString() }}">
                    <label class="form-label small" for="releve_au">Au</label>
                    <input type="date" name="au" id="releve_au" class="form-control form-control-sm mb-2" value="{{ now()->toDateString() }}">
                    <button class="btn btn-sm btn-primary w-100"><i class="bi bi-file-earmark-pdf me-1"></i>Éditer le relevé</button>
                </form>
            </div>
            @if ($du > 0 && $client->telephone && fonction('relances'))
                <form method="post" action="{{ route('clients.relancer', $client) }}" target="_blank" data-sans-confirmation>
                    @csrf
                    <button class="btn btn-success" title="{{ $client->derniere_relance_le ? 'Dernière relance : '.$client->derniere_relance_le->format('d/m/Y H:i') : 'Jamais relancé' }}">
                        <i class="bi bi-whatsapp me-1"></i>Relancer</button>
                </form>
            @endif
            @php
                $inactif = $dernierAchat && \Carbon\Carbon::parse($dernierAchat)->lt(now()->subDays(30));
                $dejaInvite = $client->derniere_invitation_le?->gt(now()->subDays(\App\Http\Controllers\ClientController::DELAI_ENTRE_INVITATIONS));
            @endphp
            @if ($client->telephone && fonction('relances') && $client->joursAvantAnniversaire() !== null && $client->joursAvantAnniversaire() < 7 && ! $client->voeuEnvoyeCetteAnnee())
                <form method="post" action="{{ route('clients.souhaiter', $client) }}" target="_blank" data-sans-confirmation>@csrf
                    <button class="btn btn-success"><i class="bi bi-gift me-1"></i>Souhaiter son anniversaire</button></form>
            @endif
            @if ($inactif && $client->telephone && fonction('relances'))
                <form method="post" action="{{ route('clients.inviter', $client) }}" target="_blank" data-sans-confirmation>
                    @csrf
                    <button class="btn btn-outline-success" @disabled($dejaInvite)
                        title="{{ $dejaInvite ? 'Déjà invité le '.$client->derniere_invitation_le->format('d/m/Y') : "Plus d'achat depuis ".\Carbon\Carbon::parse($dernierAchat)->diffForHumans(null, true) }}">
                        <i class="bi bi-envelope-heart me-1"></i>{{ $dejaInvite ? 'Invité récemment' : 'Inviter à revenir' }}</button>
                </form>
            @endif
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="bloc kpi"><div class="etiquette">Total acheté</div><div class="valeur montant">{{ gnf($totalAchats) }}</div>
            @if (boutique()->fidelite_taux > 0 || $client->points)<div class="small text-doux"><i class="bi bi-star-fill text-warning"></i> {{ number_format($client->points, 0, ',', ' ') }} points de fidélité</div>@endif
            @if ($client->avoir > 0)<div class="small text-success fw-semibold" id="soldeAvoirClient"><i class="bi bi-ticket-perforated"></i> Avoir disponible : {{ gnf($client->avoir) }}
                <a href="{{ route('clients.bon-avoir', $client) }}?imprimer=1" target="_blank" class="ms-1 fw-normal" id="bonSoldeAvoir"><i class="bi bi-printer"></i> Bon de solde</a></div>@endif</div></div>
        <div class="col-md-4"><div class="bloc kpi"><div class="etiquette">Reste à payer</div><div class="valeur montant {{ $du ? 'text-danger' : '' }}">{{ gnf($du) }}</div>
            @if ($du > 0 && $client->derniere_relance_le)<div class="small text-doux">Relancé {{ $client->derniere_relance_le->diffForHumans() }}</div>@endif</div></div>
        <div class="col-md-4">
            @if ($du > 0)
                @can('paiements.creer')
                    <form method="post" action="{{ route('credits.store', $client) }}" class="bloc bloc-corps py-2"
                          data-confirmer="Enregistrer ce versement de {{ $client->nomComplet() }} ?" data-confirmer-titre="Confirmer l'encaissement" data-confirmer-bouton="Oui, encaisser">
                        @csrf
                        <div class="small text-doux mb-1">Encaisser un versement</div>
                        <div class="d-flex flex-wrap gap-1">
                            <input name="montant" data-montant value="{{ number_format($du, 0, ',', ' ') }}" class="form-control form-control-sm" aria-label="Montant" required>
                            <select name="mode" class="form-select form-select-sm" style="flex:1 1 120px" aria-label="Mode" data-autre="autre" data-autre-placeholder="Précisez le mode">@foreach (config('gestion.modes_paiement') as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                            <button class="btn btn-sm btn-primary">OK</button>
                        </div>
                    </form>
                @endcan
            @endif
        </div>
    </div>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Achats</h2></div>
                <table class="table table-hover">
                    <thead><tr><th>N°</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Reste</th><th>État</th></tr></thead>
                    <tbody>
                    @forelse ($ventes as $v)
                        <tr><td class="text-nowrap"><a href="{{ route('ventes.show', $v) }}">{{ $v->numero }}</a></td><td class="text-nowrap">{{ $v->date_vente->format('d/m/Y') }}</td>
                            <td class="text-end montant">{{ gnf($v->total_ttc) }}</td><td class="text-end montant">{{ $v->resteAPayer() ? gnf($v->resteAPayer()) : '—' }}</td>
                            <td>@include('partials.etat-vente', ['vente' => $v])</td></tr>
                    @empty
                        <tr><td colspan="5" class="vide">Aucun achat.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $ventes->links() }}</div>
        </div>
        <div class="col-lg-4">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Derniers paiements</h2></div>
                <div class="bloc-corps">
                    @forelse ($paiements as $p)
                        <div class="d-flex justify-content-between py-2 border-bottom"><div>{{ $p->libelleMode() }}<div class="small text-doux">{{ $p->date_paiement->format('d/m/Y') }}</div></div>
                            <strong class="montant">{{ gnf($p->montant) }}</strong></div>
                    @empty
                        <p class="text-doux mb-0">Aucun paiement.</p>
                    @endforelse
                </div>
            </div>
            @if ($avoirs->isNotEmpty())
                <div class="bloc mt-3">
                    <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-ticket-perforated me-1"></i>Avoirs</h2><strong class="montant">{{ gnf($client->avoir) }}</strong></div>
                    <div class="bloc-corps">
                        @foreach ($avoirs as $a)
                            <div class="d-flex justify-content-between gap-2 py-2 border-bottom small"><div>{{ $a->motif }}<div class="text-doux">{{ $a->created_at->format('d/m/Y') }}</div></div>
                                <strong class="montant text-nowrap {{ $a->montant > 0 ? 'text-success' : '' }}">{{ $a->montant > 0 ? '+ ' : '− ' }}{{ gnf(abs($a->montant)) }}</strong></div>
                        @endforeach
                    </div>
                </div>
            @endif
            @if ($prixNegocies->isNotEmpty() || $produitsPrix->isNotEmpty())
                {{-- Prix convenus avec ce client : appliqués d'office à la caisse dès qu'il est sélectionné --}}
                <div class="bloc mt-3" id="prixNegocies">
                    <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-tags me-1"></i>Prix convenus</h2><span class="small text-doux">{{ $prixNegocies->count() }} produit(s)</span></div>
                    <div class="bloc-corps">
                        @forelse ($prixNegocies as $pn)
                            @php
                                $normal = (int) ($pn->produit?->prix_vente ?? 0);
                                $ecart = $normal > 0 ? round(($normal - $pn->prix) / $normal * 100, 1) : null;
                            @endphp
                            <div class="d-flex justify-content-between align-items-center gap-2 py-2 border-bottom small">
                                <div class="min-w-0"><div class="fw-semibold">{{ $pn->produit?->designation ?? 'Produit supprimé' }}</div>
                                    <div class="text-doux"><s>{{ gnf($normal) }}</s>@if ($ecart !== null) · − {{ number_format($ecart, 1, ',', ' ') }} %@endif
                                        @if ($pn->produit && $pn->prix >= $normal)<span class="text-danger"> · plus avantageux que le prix normal : sans effet</span>@endif</div></div>
                                <div class="d-flex align-items-center gap-2"><strong class="montant text-nowrap text-success">{{ gnf($pn->prix) }}</strong>
                                    @can('ventes.remise')
                                        <form method="post" action="{{ route('clients.prix.destroy', [$client, $pn]) }}" data-confirmer="Retirer ce prix convenu ? Le prix normal s'appliquera." data-confirmer-bouton="Retirer">
                                            @csrf @method('delete')<button class="btn btn-sm btn-link text-danger p-0" aria-label="Retirer le prix convenu"><i class="bi bi-x-lg"></i></button></form>
                                    @endcan</div>
                            </div>
                        @empty
                            <p class="small text-doux mb-2">Aucun prix convenu : ce client paie les prix normaux (ou de gros s'il est grossiste).</p>
                        @endforelse
                        @can('ventes.remise')
                            <form method="post" action="{{ route('clients.prix.store', $client) }}" class="mt-2">@csrf
                                <select name="produit_id" class="form-select form-select-sm mb-2 @error('produit_id') is-invalid @enderror" aria-label="Produit" required>
                                    <option value="">Produit…</option>
                                    @foreach ($produitsPrix as $p)<option value="{{ $p->id }}" @selected(old('produit_id') == $p->id)>{{ $p->designation }} — {{ gnf($p->prix_vente) }}</option>@endforeach
                                </select>
                                <div class="input-group input-group-sm">
                                    <input name="prix" data-montant inputmode="numeric" value="{{ old('prix') }}" class="form-control text-end @error('prix') is-invalid @enderror" placeholder="Prix convenu (GNF)" aria-label="Prix convenu" required>
                                    <button class="btn btn-outline-primary">Enregistrer</button>
                                </div>
                                @error('prix')<div class="small text-danger mt-1">{{ $message }}</div>@enderror
                                <div class="form-text">Appliqué automatiquement à la caisse et sur les devis dès que ce client est choisi (vente à l'unité).</div>
                            </form>
                        @endcan
                    </div>
                </div>
            @endif
            @if ($client->notes)<div class="bloc bloc-corps mt-3"><strong>Notes :</strong> {{ $client->notes }}</div>@endif
            @can('clients.gerer')
                <form method="post" action="{{ route('clients.destroy', $client) }}" class="mt-3" data-confirmer="Supprimer ce client ? Son historique d'achats est conservé.">@csrf @method('delete')
                    <button class="btn btn-sm btn-outline-danger">Supprimer le client</button></form>
            @endcan
        </div>
    </div>
@endsection
