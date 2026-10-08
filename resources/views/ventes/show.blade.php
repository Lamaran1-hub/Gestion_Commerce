@extends('layouts.app')
@section('titre', 'Vente '.$vente->numero)
@section('contenu')
    <div class="entete-page">
        <div>
            <h1>Vente {{ $vente->numero }} @include('partials.etat-vente')</h1>
            <div class="text-doux">{{ $vente->date_vente->format('d/m/Y à H:i') }} · par {{ $vente->vendeur?->nomComplet() ?? '—' }}</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('ventes.recu', $vente) }}" target="_blank" class="btn btn-outline-primary" id="btnRecu"><i class="bi bi-printer me-1"></i>Reçu (ticket)</a>
            <a href="{{ route('ventes.facture', $vente) }}" target="_blank" class="btn btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>Facture A4</a>
            @can('ventes.creer')<a href="{{ route('ventes.create') }}" class="btn btn-primary"><i class="bi bi-cart-plus me-1"></i>Nouvelle vente</a>@endcan
        </div>
    </div>

    @if (session('bon_avoir'))
        <div class="alert alert-success d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-ticket-perforated fs-5"></i><div class="flex-grow-1">Remettez au client son bon d'avoir : il le présentera à son prochain achat.</div>
            <a href="{{ session('bon_avoir') }}?imprimer=1" target="_blank" class="btn btn-sm btn-success" id="imprimerBonAvoir"><i class="bi bi-printer me-1"></i>Imprimer le bon d'avoir</a>
        </div>
    @endif
    @if ($vente->statut === 'validee' && ($nbSeries = app(\App\Services\NumerosSerie::class)->manquants($vente)))
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2"><i class="bi bi-upc-scan"></i>
            <span class="me-auto">{{ $nbSeries }} numéro(s) de série à noter pour cette vente : ils figureront sur le reçu et serviront pour la garantie.</span>
            <a href="#series" class="btn btn-sm btn-warning">Saisir les numéros</a></div>
    @endif
    @if ($vente->statut === 'annulee')
        <div class="alert alert-danger">Vente annulée le {{ $vente->annulee_le->format('d/m/Y H:i') }} : {{ $vente->motif_annulation }}. Le stock a été réintégré.</div>
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Articles</h2><span>Client : <strong>{{ $vente->client?->nomComplet() ?? 'Client comptoir' }}</strong></span></div>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Produit</th><th class="text-end">Quantité</th><th class="text-end">Prix unitaire</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                        @foreach ($vente->lignes as $l)
                            <tr><td>{{ $l->designation }}
                                    @if ($l->quantite_retournee > 0)<div class="small text-warning-emphasis"><i class="bi bi-arrow-return-left"></i> {{ qte($l->quantite_retournee) }} retourné(s)</div>@endif</td>
                                <td class="text-end">{{ qte($l->quantite) }}</td>
                                <td class="text-end montant">{{ gnf($l->prix_unitaire) }}</td><td class="text-end montant">{{ gnf($l->total) }}</td></tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                            @if ($vente->montant_retourne)
                                <tr><td colspan="3" class="text-end text-doux">Montant initial de la vente</td><td class="text-end montant text-doux">{{ gnf($vente->totalInitial()) }}</td></tr>
                                <tr><td colspan="3" class="text-end text-doux">Retours (avoirs)</td><td class="text-end montant text-doux">− {{ gnf($vente->montant_retourne) }}</td></tr>
                            @endif
                            <tr><td colspan="3" class="text-end text-doux">Sous-total</td><td class="text-end montant">{{ gnf($vente->sousTotal()) }}</td></tr>
                            @if ($vente->remise)<tr><td colspan="3" class="text-end text-doux">Remise</td><td class="text-end montant">− {{ gnf($vente->remise) }}</td></tr>@endif
                            @if ($vente->total_tva || collect($vente->ventilationTva())->count() > 1)
                                @foreach ($vente->ventilationTva() as $v)
                                    <tr><td colspan="3" class="text-end text-doux">{{ $v['taux'] > 0 ? $vente->libelleTva($v['taux']).' sur '.gnf($v['base']).($vente->prix_ttc ? ' HT' : '') : 'Exonéré de TVA (montant HT)' }}</td><td class="text-end montant">{{ $v['taux'] > 0 ? gnf($v['tva']) : gnf($v['base']) }}</td></tr>
                                @endforeach
                            @endif
                            <tr><td colspan="3" class="text-end fw-bold">Total</td><td class="text-end fw-bold montant fs-5">{{ gnf($vente->total_ttc) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            @if ($vente->note)<div class="bloc bloc-corps mt-3"><strong>Note :</strong> {{ $vente->note }}</div>@endif
            @include('partials.series-vente')

            @if ($vente->retours->isNotEmpty())
                <div class="bloc mt-3">
                    <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-arrow-return-left me-1"></i>Retours de marchandise</h2></div>
                    <table class="table mb-0"><tbody>
                        @foreach ($vente->retours as $r)
                            <tr><td class="fw-semibold">{{ $r->numero }}<div class="small text-doux">{{ $r->created_at->format('d/m/Y H:i') }} · {{ $r->auteur?->nomComplet() }}</div></td>
                                <td class="small">{{ $r->lignes->map(fn ($l) => qte($l->quantite).' × '.$l->designation)->implode(', ') }}<div class="text-doux">{{ $r->motif }}</div></td>
                                <td class="text-end montant">− {{ gnf($r->montant) }}
                                    @if ($r->mode_remboursement === \App\Services\Echanges::MODE)
                                        <div class="small text-doux">échangé : bon de {{ gnf($r->rembourse) }}
                                            @if ($r->echange_restant > 0)<a href="{{ route('ventes.create', ['echange' => $r->id]) }}" class="d-block"><i class="bi bi-arrow-left-right"></i> Utiliser le bon en caisse</a>
                                            @elseif ($r->echange_vente_id)<a href="{{ route('ventes.show', $r->echange_vente_id) }}" class="d-block">utilisé sur la vente {{ $r->venteEchange?->numero }}</a>@endif</div>
                                    @elseif ($r->rembourse)<div class="small text-doux">{{ $r->mode_remboursement === 'avoir' ? 'rendu en avoir' : 'remboursé' }} {{ gnf($r->rembourse) }}{{ $r->mode_remboursement === 'avoir' ? '' : ' ('.libelle_mode($r->mode_remboursement).')' }}
                                        @if ($r->mode_remboursement === 'avoir')<a href="{{ route('retours.bon-avoir', $r) }}?imprimer=1" target="_blank" class="d-block"><i class="bi bi-printer"></i> Bon d'avoir</a>@endif</div>
                                    @else<div class="small text-doux">déduit du reste à payer</div>@endif</td></tr>
                        @endforeach
                    </tbody></table>
                </div>
            @endif

            @if ($vente->statut === 'validee' && $vente->lignes->sum('quantite') > 0)
                @can('ventes.annuler')
                    <details class="bloc mt-3" @if ($errors->has('quantites') || old('quantites')) open @endif>
                        <summary class="bloc-entete" style="cursor:pointer"><h2 class="mb-0"><i class="bi bi-arrow-return-left me-1"></i>Enregistrer un retour de marchandise</h2></summary>
                        <form method="post" action="{{ route('ventes.retour', $vente) }}" class="bloc-corps"
                              data-confirmer="Enregistrer ce retour ? Les produits reviendront en stock et le montant sera déduit de la vente (ou remboursé)."
                              data-confirmer-titre="Retour de marchandise" data-confirmer-bouton="Oui, enregistrer le retour" data-confirmer-type="alerte">
                            @csrf
                            <table class="table table-sm">
                                <thead><tr><th>Produit</th><th class="text-end">Vendu</th><th style="width:130px">Quantité retournée</th></tr></thead>
                                <tbody>
                                @foreach ($vente->lignes->where('quantite', '>', 0) as $l)
                                    <tr><td>{{ $l->designation }}
                                            @if ($l->produit?->suivi_serie && $l->numerosSerie->isNotEmpty())
                                                {{-- Appareils suivis : on coche ceux qui reviennent, la quantité suit --}}
                                                <div class="small mt-1 series-retour" data-ligne="{{ $l->id }}" data-facteur="{{ $l->facteur ?: 1 }}">
                                                    @foreach ($l->numerosSerie as $n)
                                                        <label class="d-block"><input type="checkbox" class="form-check-input me-1" name="series_retour[{{ $l->id }}][]" value="{{ $n->id }}"
                                                            @checked(in_array($n->id, old('series_retour.'.$l->id, [])))> <span class="font-monospace">{{ $n->numero }}</span></label>
                                                    @endforeach
                                                </div>
                                            @endif</td>
                                        <td class="text-end">{{ qte($l->quantite) }}</td>
                                        <td><input type="number" step="0.01" min="0" max="{{ $l->quantite }}" name="quantites[{{ $l->id }}]" value="{{ old('quantites.'.$l->id) }}" id="qteRetour{{ $l->id }}"
                                                   class="form-control form-control-sm text-end" aria-label="Quantité retournée de {{ $l->designation }}"></td></tr>
                                @endforeach
                                </tbody>
                                <script>
                                    // Cocher un numéro de série = un appareil rendu : la quantité se met à jour
                                    document.querySelectorAll('.series-retour').forEach(bloc => bloc.addEventListener('change', () => {
                                        const n = bloc.querySelectorAll('input:checked').length;
                                        document.getElementById('qteRetour' + bloc.dataset.ligne).value = n ? n / Number(bloc.dataset.facteur) : '';
                                    }));
                                </script>
                            </table>
                            <div class="row g-2">
                                <div class="col-sm-7"><label class="form-label small mb-1" for="motif_retour">Motif</label>
                                    <select name="motif" id="motif_retour" class="form-select form-select-sm" required data-autre>
                                        <option value="">Choisir…</option>
                                        @foreach (config('gestion.motifs_retour') as $m)<option @selected(old('motif') === $m)>{{ $m }}</option>@endforeach
                                    </select></div>
                                <div class="col-sm-5"><label class="form-label small mb-1" for="mode_remboursement">Remboursement éventuel</label>
                                    <select name="mode_remboursement" id="mode_remboursement" class="form-select form-select-sm">
                                        <option value="echange" @selected(old('mode_remboursement') === 'echange')>Échange : le client prend d'autres articles (on passe en caisse)</option>
                                        @if ($vente->client_id)<option value="avoir" @selected(old('mode_remboursement') === 'avoir')>Avoir client (bon d'achat, rien ne sort de la caisse)</option>@endif
                                        @foreach (config('gestion.modes_paiement') as $k => $lib)<option value="{{ $k }}" @selected(old('mode_remboursement', 'especes') === $k)>{{ $lib }}</option>@endforeach
                                    </select></div>
                            </div>
                            <div class="form-text">Si le client a déjà payé plus que le nouveau total, la différence lui est remboursée ; sinon elle est déduite de ce qu'il doit.
                                @if (boutique()->delai_retour_jours) Retours acceptés jusqu'au {{ $vente->date_vente->copy()->addDays(boutique()->delai_retour_jours)->format('d/m/Y') }} (au-delà : administrateur).@endif</div>
                            @php
                                $verrouRetour = boutique()->periode_verrouillee_jusquau;
                                $retourClos = $verrouRetour && $vente->date_vente->copy()->startOfDay()->lte($verrouRetour);
                                $retourHorsDelai = boutique()->delai_retour_jours && $vente->date_vente->copy()->addDays(boutique()->delai_retour_jours)->endOfDay()->isPast()
                                    && ! auth()->user()->role?->systeme;
                            @endphp
                            @if ($retourClos)<div class="small text-danger mt-2">Période clôturée jusqu'au {{ $verrouRetour->format('d/m/Y') }} : plus de retour possible sur cette vente.</div>
                            @elseif ($retourHorsDelai)<div class="small text-danger mt-2">Délai de retour dépassé : seul l'administrateur peut l'accepter.</div>@endif
                            <button class="btn btn-outline-warning mt-2" @disabled($retourClos || $retourHorsDelai)><i class="bi bi-arrow-return-left me-1"></i>Enregistrer le retour</button>
                        </form>
                    </details>
                @endcan
            @endif
        </div>
        <div class="col-lg-4">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Paiements</h2></div>
                <div class="bloc-corps">
                    @forelse ($vente->paiements as $p)
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <div>{{ $p->libelleMode() }}<div class="small text-doux">{{ $p->date_paiement->format('d/m/Y H:i') }}{{ $p->reference ? ' · '.$p->reference : '' }}</div></div>
                            <strong class="montant">{{ gnf($p->montant) }}</strong>
                        </div>
                    @empty
                        <p class="text-doux">Aucun paiement reçu.</p>
                    @endforelse
                    <div class="d-flex justify-content-between pt-3 fs-5"><span>Reste à payer</span><strong class="montant {{ $vente->resteAPayer() ? 'text-danger' : '' }}">{{ gnf($vente->resteAPayer()) }}</strong></div>
                    @if ($vente->resteAPayer() > 0 && $vente->echeance)
                        <div class="d-flex justify-content-between align-items-center small mt-1" id="echeance">
                            <span class="text-doux">À payer avant le</span>
                            <span><strong>{{ $vente->echeance->format('d/m/Y') }}</strong>
                                @if ($vente->creditEnRetard())<span class="etat etat-rupture ms-1">en retard de {{ (int) $vente->echeance->diffInDays(now()->startOfDay()) }} j</span>
                                @elseif ($vente->echeance->lte(now()->addDays(7)))<span class="etat etat-alerte ms-1">{{ $vente->echeance->isToday() ? "aujourd'hui" : 'dans '.(int) now()->startOfDay()->diffInDays($vente->echeance).' j' }}</span>@endif</span>
                        </div>
                    @endif
                </div>
                @if ($vente->resteAPayer() > 0)
                    @can('paiements.creer')
                        @if ($vente->client_id)
                            {{-- Le client demande un délai : nouvelle date de paiement promise --}}
                            <details class="bloc-corps border-top py-2">
                                <summary class="small fw-semibold">Reporter l'échéance</summary>
                                <form method="post" action="{{ route('ventes.echeance', $vente) }}" class="row g-2 mt-1" data-sans-confirmation>
                                    @csrf
                                    <div class="col-6"><label class="form-label small mb-1" for="nouvelle_echeance">Nouvelle date</label>
                                        <input type="date" name="echeance" id="nouvelle_echeance" class="form-control form-control-sm" required
                                               value="{{ ($vente->echeance && $vente->echeance->isFuture() ? $vente->echeance : now())->copy()->addDays(15)->toDateString() }}"
                                               min="{{ now()->toDateString() }}" max="{{ now()->addYear()->toDateString() }}"></div>
                                    <div class="col-6"><label class="form-label small mb-1" for="motif_echeance">Motif (facultatif)</label>
                                        <input name="motif" id="motif_echeance" class="form-control form-control-sm" maxlength="150" placeholder="Ex. : salaire le 15"></div>
                                    <div class="col-12 d-grid"><button class="btn btn-sm btn-outline-primary">Enregistrer la nouvelle date</button></div>
                                </form>
                            </details>
                        @endif
                        <form method="post" action="{{ route('ventes.paiement', $vente) }}" class="bloc-corps border-top row g-2"
                              data-confirmer="Enregistrer ce paiement pour la vente {{ $vente->numero }} ?" data-confirmer-titre="Confirmer l'encaissement" data-confirmer-bouton="Oui, encaisser">
                            @csrf
                            <div class="col-12"><h3 class="h6 mb-1">Encaisser un paiement</h3></div>
                            <div class="col-6"><input name="montant" data-montant value="{{ number_format($vente->resteAPayer(), 0, ',', ' ') }}" class="form-control" aria-label="Montant" required></div>
                            <div class="col-6">
                                <select name="mode" class="form-select" aria-label="Mode de paiement" data-autre="autre" data-autre-placeholder="Précisez le mode (facultatif)">
                                    @foreach (config('gestion.modes_paiement') as $cle => $lib)<option value="{{ $cle }}">{{ $lib }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-12"><input name="reference" class="form-control form-control-sm" placeholder="N° de transaction (facultatif)"></div>
                            <div class="col-12 d-grid"><button class="btn btn-primary">Encaisser</button></div>
                        </form>
                    @endcan
                @endif
            </div>
            @if ($vente->statut === 'validee' || $vente->livraison)
                @include('partials.livraison-vente')
            @endif
            @if ($vente->statut === 'validee')
                @can('ventes.annuler')
                    <form method="post" action="{{ route('ventes.annuler', $vente) }}" class="bloc bloc-corps mt-3" data-confirmer="Annuler cette vente ? Les produits seront remis en stock.">
                        @csrf
                        <h3 class="h6">Annuler la vente</h3>
                        <label class="form-label small text-doux mb-1" for="motif_annulation">Motif de l'annulation</label>
                        <select name="motif" id="motif_annulation" class="form-select form-select-sm" required data-autre>
                            <option value="">Choisir un motif…</option>
                            @foreach (config('gestion.motifs_annulation') as $m)<option>{{ $m }}</option>@endforeach
                        </select>
                        <div class="mb-2"></div>
                        @php
                            // Règles des paramètres : période clôturée, puis délai d'annulation (au-delà, administrateur seulement)
                            $verrou = boutique()->periode_verrouillee_jusquau;
                            $periodeClose = $verrou && $vente->date_vente->copy()->startOfDay()->lte($verrou);
                            $delaiAnnulation = boutique()->delai_annulation_heures;
                            $horsDelai = $delaiAnnulation && $vente->date_vente->copy()->addHours($delaiAnnulation)->isPast() && ! auth()->user()->role?->systeme;
                            $repriseEnAvoir = app(\App\Services\Avoirs::class)->avoirEmisSur($vente);
                            $dejaLivree = $vente->livraison === 'livree';
                        @endphp
                        @if ($dejaLivree)
                            <div class="small text-danger mb-2">Vente déjà livrée : pour reprendre la marchandise, enregistrez un retour.</div>
                        @elseif ($repriseEnAvoir)
                            <div class="small text-danger mb-2">Des articles ont été repris en avoir : pour le reste, enregistrez un retour plutôt qu'une annulation.</div>
                        @elseif ($periodeClose)
                            <div class="small text-danger mb-2">Période clôturée jusqu'au {{ $verrou->format('d/m/Y') }} : cette vente ne peut plus être annulée.</div>
                        @elseif ($horsDelai)
                            <div class="small text-danger mb-2">Vente de plus de {{ $delaiAnnulation }} h : seul l'administrateur peut l'annuler.</div>
                        @elseif ($delaiAnnulation)
                            <div class="small text-doux mb-2">Annulation possible jusqu'au {{ $vente->date_vente->copy()->addHours($delaiAnnulation)->format('d/m/Y à H:i') }}.</div>
                        @endif
                        <button class="btn btn-sm btn-outline-danger" @disabled($periodeClose || $horsDelai || $repriseEnAvoir || $dejaLivree)>Annuler et remettre en stock</button>
                    </form>
                @endcan
            @endif
        </div>
    </div>
@endsection
@if (request('imprimer'))
    @push('scripts')
    <script>
        document.getElementById('btnRecu')?.focus();
        @if (session('succes'))
            // Écran client : la vente est enregistrée, on remercie le client (la monnaie vient du dernier ticket affiché)
            if ('BroadcastChannel' in window) {
                const canal = new BroadcastChannel('gn-ecran-client-{{ $vente->boutique_id }}-{{ auth()->id() }}');
                canal.postMessage({ type: 'merci', numero: @json($vente->numero), total: {{ (int) $vente->total_ttc }} });
                canal.close();
            }
        @endif
    </script>
    @endpush
@endif
