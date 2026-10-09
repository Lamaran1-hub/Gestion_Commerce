@extends('layouts.app')
@section('titre', request()->boolean('proforma') ? 'Nouvelle facture proforma' : 'Nouvelle vente')
@section('contenu')
    @php
        // Mode proforma : même écran, mais ni paiement ni sortie de stock (enregistré comme devis / facture proforma)
        $proforma = request()->boolean('proforma');
        $peutRemise = auth()->user()->aPermission('ventes.remise');
        $promos = app(\App\Services\Promotions::class);
        $peutCreerClient = auth()->user()->aPermission('clients.gerer');
        $catalogue = $produits->map(fn ($p) => ['id' => $p->id, 'nom' => $p->designation, 'code' => $p->code_barre, 'prix' => $p->prix_vente, 'gros' => $p->prix_gros, 'qteGros' => $p->quantite_gros ? (float) $p->quantite_gros : null, 'stock' => (float) $p->stock, 'unite' => $p->unite,
            'cond' => $p->aConditionnement() ? $p->conditionnement : null, 'qteCond' => $p->aConditionnement() ? (float) $p->qte_conditionnement : null,
            'prixCond' => $p->aConditionnement() ? $p->prixConditionnement() : null, 'tva' => \App\Support\Tva::tauxProduit($p),
            'promo' => $promos->prix($p), 'promoCond' => $p->aConditionnement() ? $promos->prix($p, true) : null, 'img' => $p->imageUrl()]);
    @endphp
    @if ($proforma)
        <div class="alert alert-info d-flex align-items-center gap-2"><i class="bi bi-file-earmark-text fs-5"></i>
            <div class="flex-grow-1"><strong>Facture proforma</strong> : choisissez les produits et le client. Rien ne sort du stock et aucun paiement n'est encaissé ;
                les prix sont garantis {{ boutique()->validite_devis_jours ?: 15 }} jours. Vous pourrez ensuite la transformer en vente.</div>
            <a href="{{ route('ventes.create') }}" class="btn btn-sm btn-light">Revenir à la vente</a></div>
    @elseif (app(\App\Services\CaisseService::class)->estCloturee(auth()->user()))
        <div class="alert alert-warning d-flex align-items-center gap-2"><i class="bi bi-lock fs-5"></i>
            <div>Votre caisse est <strong>clôturée pour aujourd'hui</strong> : les nouvelles ventes seront refusées.
                Demandez à l'administrateur de la boutique de la rouvrir si nécessaire.</div></div>
    @endif
    <div class="caisse-grille">
        <section class="bloc">
            <div class="bloc-entete flex-wrap">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-upc-scan"></i></span>
                    <input id="recherche" class="form-control form-control-lg" placeholder="Produit ou code-barres" autocomplete="off" autofocus>
                    <button type="button" class="btn btn-outline-primary d-none" id="scanCamera" title="Scanner avec la caméra" aria-label="Scanner un code-barres avec la caméra"><i class="bi bi-camera"></i></button>
                </div>
                @if (! $proforma && fonction('equipe') && auth()->user()->objectif_mensuel)
                    @php
                        $monObjectif = app(\App\Services\Objectifs::class)->vendeur(auth()->user());
                    @endphp
                    <div class="mt-2 small w-100" id="monObjectif" title="Votre objectif du mois">
                        <div class="d-flex justify-content-between gap-2"><span><i class="bi bi-bullseye me-1"></i>Mon mois : <strong class="montant text-nowrap">{{ gnf($monObjectif['realise']) }}</strong> sur <span class="text-nowrap">{{ gnf($monObjectif['objectif']) }}</span></span>
                            <strong class="text-nowrap">{{ $monObjectif['pct'] }} %</strong></div>
                        <div class="progress mt-1" style="height:6px" role="progressbar" aria-label="Mon objectif du mois" aria-valuenow="{{ $monObjectif['pct'] }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar {{ $monObjectif['pct'] >= 100 ? 'bg-success' : 'bg-primary' }}" style="width: {{ min(100, $monObjectif['pct']) }}%"></div></div>
                        @if ($monObjectif['pct'] < 100 && $monObjectif['jours_restants'] > 0)<div class="text-doux">Encore {{ gnf($monObjectif['par_jour']) }} {{ $monObjectif['jours_restants'] > 1 ? 'par jour' : "aujourd'hui" }} pour l'atteindre.</div>@endif
                    </div>
                @endif
                <div class="modal fade" id="modalScan" tabindex="-1" aria-labelledby="titreScan" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                        <div class="modal-header py-2"><h2 class="modal-title h6" id="titreScan"><i class="bi bi-camera me-1"></i>Scanner les articles</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
                        <div class="modal-body p-2">
                            <div class="position-relative bg-dark rounded overflow-hidden" style="aspect-ratio:4/3">
                                <video id="videoScan" class="w-100 h-100" style="object-fit:cover" playsinline muted></video>
                                <div class="position-absolute top-50 start-0 end-0 border-top border-2 border-danger opacity-75" aria-hidden="true"></div>
                            </div>
                            <div class="small mt-2" id="etatScan" role="status" aria-live="polite">Placez le code-barres dans le cadre.</div>
                        </div>
                        <div class="modal-footer py-2"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Terminé</button></div>
                    </div></div>
                </div>
            </div>
            <div class="bloc-corps">
                <div id="tuiles" class="row g-2"></div>
                <p id="aucun" class="vide d-none">Aucun produit ne correspond. Vérifiez l'orthographe ou le code-barres.</p>
            </div>
        </section>

        <form method="post" action="{{ $proforma ? route('devis.store') : route('ventes.store') }}" id="formVente" class="bloc ticket">
            @csrf
            <div class="bloc-entete">
                <h2 class="mb-0">{{ $proforma ? 'Proforma' : 'Ticket' }} <span id="reseau" class="indicateur-reseau en-ligne align-middle"><i class="bi bi-wifi"></i><span>En ligne</span></span></h2>
                <div class="d-flex align-items-center gap-1">
                    @if ($enAttente->isNotEmpty())
                        <div class="dropdown">
                            <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-pause-circle me-1"></i>En attente <span class="badge bg-primary">{{ $enAttente->count() }}</span></button>
                            <div class="dropdown-menu dropdown-menu-end p-2" style="min-width:300px">
                                @foreach ($enAttente as $a)
                                    <div class="d-flex align-items-center gap-2 py-1 {{ $loop->last ? '' : 'border-bottom' }}">
                                        <div class="flex-grow-1 small"><div class="fw-semibold">{{ $a->libelle ?: 'Ticket sans nom' }}</div>
                                            <div class="text-doux">{{ $a->nbArticles() }} article(s) · {{ gnf($a->total) }} · {{ $a->created_at->diffForHumans() }} · {{ $a->auteur?->prenom }}</div></div>
                                        <a href="{{ route('ventes.attente.reprendre', $a) }}" class="btn btn-sm btn-primary">Reprendre</a>
                                        <button type="submit" form="supprimerAttente{{ $a->id }}" class="btn btn-sm btn-link text-danger p-0" aria-label="Supprimer ce ticket">✕</button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @unless ($proforma)
                        <a href="{{ route('ventes.ecran-client') }}" target="ecranClient" id="ouvrirEcranClient" class="btn btn-sm btn-outline-secondary"
                           title="Ouvrir l'écran tourné vers le client (tablette ou second écran)"><i class="bi bi-display"></i><span class="d-none d-xl-inline ms-1">Écran client</span></a>
                    @endunless
                    <button type="button" id="vider" class="btn btn-sm btn-link text-danger">Vider</button>
                </div>
            </div>
            <div class="bloc-corps pb-2">
                <label class="form-label small text-doux mb-1" for="client_id">Client</label>
                @unless ($clientsTous)
                    {{-- Beaucoup de clients : seuls les habitués sont dans la liste, les autres se trouvent ici --}}
                    <div class="position-relative mb-1">
                        <input type="search" id="rechercheClient" class="form-control form-control-sm" autocomplete="off" data-url="{{ route('ventes.clients') }}"
                               placeholder="Chercher parmi les {{ number_format($nbClients, 0, ',', ' ') }} clients (nom, téléphone, code)" aria-label="Chercher un client">
                        <div id="resultatsClients" class="list-group position-absolute w-100 shadow-sm d-none" style="z-index:20;max-height:16rem;overflow:auto"></div>
                    </div>
                @endunless
                <div class="input-group mb-2">
                    <select name="client_id" id="client_id" class="form-select">
                        <option value="">Client comptoir (sans nom)</option>
                        @foreach ($clients as $c)
                            @php($d = \App\Support\ClientCaisse::donnees($c, $dettes[$c->id] ?? null))
                            <option value="{{ $d['id'] }}" data-grossiste="{{ $d['grossiste'] }}" data-points="{{ $d['points'] }}" data-avoir="{{ $d['avoir'] }}" data-anniv="{{ $d['anniv'] }}"
                                    data-du="{{ $d['du'] }}" data-plafond="{{ $d['plafond'] }}" data-retard="{{ $d['retard'] }}" @selected($clientChoisi === $c->id)>{{ $d['libelle'] }}</option>
                        @endforeach
                    </select>
                    @if ($peutCreerClient)
                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalClient" title="Nouveau client" aria-label="Nouveau client"><i class="bi bi-person-plus"></i></button>
                    @endif
                </div>
                @if ($proforma)
                    <input name="client_nom" value="{{ old('client_nom') }}" class="form-control form-control-sm mb-2" maxlength="120"
                           placeholder="…ou nom du client / de la société (s'il n'est pas dans la liste)" aria-label="Nom du client pour la proforma">
                    <input name="note" value="{{ old('note') }}" class="form-control form-control-sm mb-2" maxlength="500" placeholder="Note sur la proforma (conditions, délai de livraison…)" aria-label="Note">
                @endif
                <input type="hidden" name="attente_id" value="{{ old('attente_id') }}">
                @if (old('attente_id'))<div class="alert alert-info py-1 small mb-2"><i class="bi bi-pause-circle me-1"></i>Ticket en attente repris.</div>@endif
                @if ($echange && ! $proforma)
                    {{-- Échange : le bon issu du retour paie les nouveaux articles --}}
                    <input type="hidden" name="echange_id" value="{{ $echange->id }}">
                    <div class="alert alert-success py-2 small mb-2" id="blocEchange" data-solde="{{ $echange->echange_restant }}">
                        <div class="d-flex justify-content-between gap-2 flex-wrap">
                            <strong><i class="bi bi-arrow-left-right me-1"></i>Échange {{ $echange->numero }} : bon de {{ gnf($echange->echange_restant) }}</strong>
                            <a href="{{ route('ventes.show', $echange->vente_id) }}" class="small">Vente {{ $echange->vente?->numero }}</a>
                        </div>
                        <div class="text-doux">Rapporté : {{ $echange->lignes->map(fn ($l) => qte($l->quantite).' × '.$l->designation)->join(', ') }}</div>
                        <div class="fw-semibold mt-1" id="etatEchange" aria-live="polite">Ajoutez les articles que le client prend à la place.</div>
                    </div>
                @endif
                <div id="lignes"><p class="text-doux small my-3" id="ticketVide">Cliquez sur un produit ou scannez son code-barres.</p></div>
                <div id="champsLignes"></div>

                <div class="d-flex justify-content-between mt-3"><span class="text-doux">Sous-total</span><span id="sousTotal" class="montant">0 GNF</span></div>
                @if ($peutRemise)
                    <div class="d-flex justify-content-between align-items-center mt-2 gap-2">
                        <label for="remise" class="text-doux">Remise
                            @if (boutique()->remise_max_pct !== null)<span class="small">(max {{ rtrim(rtrim(number_format(boutique()->remise_max_pct, 2, ',', ''), '0'), ',') }} %)</span>@endif</label>
                        <input name="remise" id="remise" data-montant value="{{ old('remise') }}" class="form-control form-control-sm text-end" style="max-width:150px" inputmode="numeric" placeholder="0">
                    </div>
                    <div class="small text-danger text-end d-none" id="alerteRemise" aria-live="polite"></div>
                @endif
                @if (boutique()->tva_active)
                    <div class="d-flex justify-content-between mt-2"><span class="text-doux">{{ \App\Services\VenteService::prixTtc() ? 'dont TVA' : 'TVA' }} <span class="small">({{ \App\Services\VenteService::prixTtc() ? 'comprise dans les prix' : 'par produit, exonérés à 0 %' }})</span></span><span id="tva" class="montant">0 GNF</span></div>
                @endif

                {{-- Paiement : masqué en mode proforma (rien n'est encaissé) --}}
                <div class="{{ $proforma ? 'd-none' : '' }}" id="blocPaiement">
                @if (boutique()->fidelite_taux > 0 && fonction('fidelite'))
                    <div class="form-check mt-2 d-none" id="blocPoints">
                        <input type="checkbox" name="utiliser_points" value="1" id="utiliser_points" class="form-check-input">
                        <label for="utiliser_points" class="form-check-label small">Utiliser ses points de fidélité (<span id="soldePoints">0</span> disponibles)</label>
                        <div class="small text-success fw-semibold d-none" id="payePoints"></div>
                    </div>
                @endif
                <div class="small text-success fw-semibold mt-1 d-none" id="annivClient" role="status"></div>
                <div class="form-check mt-2 d-none" id="blocAvoir">
                    <input type="checkbox" name="utiliser_avoir" value="1" id="utiliser_avoir" class="form-check-input" checked>
                    <label for="utiliser_avoir" class="form-check-label small">Utiliser son avoir (<span id="soldeAvoir">0 GNF</span> disponibles)</label>
                    <div class="small text-success fw-semibold d-none" id="payeAvoir"></div>
                </div>
                {{-- Carte cadeau : le client donne le code imprimé sur sa carte, le solde est vérifié auprès du serveur --}}
                <button type="button" class="btn btn-sm btn-link px-0 mt-1" id="ouvrirCarte"><i class="bi bi-gift me-1"></i>Payer avec une carte cadeau</button>
                <div class="d-none mt-1" id="blocCarte">
                    <div class="input-group input-group-sm">
                        <input name="carte_cadeau" id="carte_cadeau" class="form-control text-uppercase font-monospace" maxlength="20" autocomplete="off"
                               placeholder="Code de la carte (CC-XXXX-XXXX)" aria-label="Code de la carte cadeau" data-url="{{ route('cartes-cadeaux.verifier') }}">
                        <button type="button" class="btn btn-outline-primary" id="verifierCarte">Vérifier</button>
                        <button type="button" class="btn btn-outline-secondary" id="retirerCarte" aria-label="Retirer la carte cadeau">✕</button>
                    </div>
                    <div class="small mt-1" id="etatCarte" aria-live="polite"></div>
                </div>
                <div class="row g-2 mt-2">
                    <div class="col-6">
                        <label class="form-label small text-doux mb-1" for="mode">Paiement</label>
                        <select name="mode" id="mode" class="form-select" data-autre="autre" data-autre-placeholder="Précisez le mode (facultatif)">
                            @foreach (config('gestion.modes_paiement') as $cle => $libelle)
                                <option value="{{ $cle }}" @selected(old('mode', 'especes') === $cle)>{{ $libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-doux mb-1" for="montant_recu">Montant reçu</label>
                        <input name="montant_recu" id="montant_recu" data-montant value="{{ old('montant_recu') }}" class="form-control text-end" inputmode="numeric" placeholder="= total">
                    </div>
                    <div class="col-12 d-none" id="blocReference">
                        <input name="reference" id="reference" value="{{ old('reference') }}" class="form-control form-control-sm" placeholder="N° de transaction (conseillé)">
                    </div>
                </div>
                {{-- Paiement mixte : ex. une partie en Orange Money, le reste en espèces --}}
                <div id="autresPaiements"></div>
                <template id="modeleAutrePaiement">
                    <div class="row g-1 mt-1 align-items-center autre-paiement">
                        <div class="col-5"><select class="form-select form-select-sm" data-champ="mode" aria-label="Autre moyen de paiement">
                            @foreach (config('gestion.modes_paiement') as $cle => $libelle)<option value="{{ $cle }}" @selected($cle === 'orange_money')>{{ $libelle }}</option>@endforeach
                        </select></div>
                        <div class="col-4"><input data-champ="montant" data-montant inputmode="numeric" class="form-control form-control-sm text-end" placeholder="Montant" aria-label="Montant"></div>
                        <div class="col-2"><input data-champ="reference" class="form-control form-control-sm" placeholder="Réf." aria-label="Référence"></div>
                        <div class="col-1"><button type="button" class="btn btn-sm btn-link text-danger p-0 retirer-paiement" aria-label="Retirer ce paiement">✕</button></div>
                    </div>
                </template>
                <button type="button" class="btn btn-sm btn-link px-0 mt-1" id="ajouterPaiement"><i class="bi bi-plus-circle me-1"></i>Payer avec plusieurs moyens</button>
                <div id="info" class="small mt-2" aria-live="polite"></div>
                {{-- Vente à crédit : date à laquelle le client promet de payer --}}
                <div class="d-none mt-2" id="blocEcheance">
                    <label class="form-label small text-doux mb-1" for="echeance">Reste à payer avant le</label>
                    <input type="date" name="echeance" id="echeance" class="form-control form-control-sm" style="max-width:190px"
                           value="{{ old('echeance', \App\Models\Vente::echeanceParDefaut(now())->toDateString()) }}" min="{{ now()->toDateString() }}" max="{{ now()->addYear()->toDateString() }}">
                </div>
                </div>
            </div>
            <div class="ticket-total">
                <div class="d-flex justify-content-between align-items-end">
                    <div><div style="color:#A9C0B6">{{ $proforma ? 'Total de la proforma' : 'Total à payer' }}</div><div class="valeur montant" id="total">0 GNF</div></div>
                    <button class="btn btn-light btn-lg fw-bold" id="valider" disabled>
                        @if ($proforma)
                            <i class="bi bi-file-earmark-check me-1"></i>Enregistrer la proforma
                        @else
                            <i class="bi bi-check2-circle me-1"></i>Valider
                        @endif
                    </button>
                </div>
                @unless ($proforma)
                {{-- Même ticket, sans sortie de stock ni paiement : devis / facture proforma --}}
                <div class="d-flex gap-2 mt-2">
                    <button class="btn btn-sm btn-outline-light flex-fill" id="enAttente" formaction="{{ route('ventes.attente.store') }}" formnovalidate disabled
                            title="Mettre ce ticket de côté et servir le client suivant"><i class="bi bi-pause-circle me-1"></i>Mettre en attente</button>
                    <button class="btn btn-sm btn-outline-light flex-fill" id="enDevis" formaction="{{ route('devis.store') }}" formnovalidate disabled>
                        <i class="bi bi-file-earmark-text me-1"></i>Devis (proforma)</button>
                </div>
                @endunless
            </div>
        </form>
        @foreach ($enAttente as $a)
            <form method="post" action="{{ route('ventes.attente.destroy', $a) }}" id="supprimerAttente{{ $a->id }}" class="d-none"
                  data-confirmer="Supprimer ce ticket en attente ?" data-confirmer-titre="Supprimer le ticket" data-confirmer-bouton="Oui, supprimer">
                @csrf @method('delete')
            </form>
        @endforeach
    </div>

    <div class="total-mobile">
        <div><div class="small" style="color:#A9C0B6"><span id="nbArticles">0</span> article(s)</div><div class="fw-bold fs-5 montant" id="totalMobile">0 GNF</div></div>
        <a href="#formVente" class="btn btn-light fw-semibold">Voir le ticket</a>
    </div>

    @if ($peutCreerClient)
        <div class="modal fade" id="modalClient" tabindex="-1" aria-labelledby="titreModalClient" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" id="formClient">
                    <div class="modal-header"><h2 class="modal-title h5" id="titreModalClient">Nouveau client</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
                    <div class="modal-body row g-3">
                        <div class="col-6"><label class="form-label" for="nc_prenom">Prénom</label><input id="nc_prenom" name="prenom" class="form-control"></div>
                        <div class="col-6"><label class="form-label" for="nc_nom">Nom</label><input id="nc_nom" name="nom" class="form-control" required></div>
                        <div class="col-12"><label class="form-label" for="nc_tel">Téléphone</label><input id="nc_tel" name="telephone" class="form-control"></div>
                        <div class="col-6"><label class="form-label" for="nc_quartier">Quartier de résidence</label><input id="nc_quartier" name="quartier" class="form-control"></div>
                        <div class="col-6"><label class="form-label" for="nc_ville">Ville</label><input id="nc_ville" name="ville" class="form-control"></div>
                        <div class="col-12 text-danger small" id="erreurClient"></div>
                    </div>
                    <div class="modal-footer"><button class="btn btn-primary">Ajouter le client</button></div>
                </form>
            </div>
        </div>
    @endif
@endsection
@push('scripts')
<script>
(() => {
    document.body.classList.add('page-caisse');
    // Téléphone : la barre « Voir le ticket » s'efface quand le total du ticket est déjà visible
    const totalTicket = document.querySelector('.ticket-total');
    if (totalTicket && 'IntersectionObserver' in window) {
        new IntersectionObserver(([e]) => document.querySelector('.total-mobile')?.classList.toggle('masquee', e.isIntersecting))
            .observe(totalTicket);
    }
    const catalogue = @json($catalogue);
    const tauxTva = {{ boutique()->tva_active ? (float) boutique()->tva_taux : 0 }};
    const prixTtc = @json(\App\Services\VenteService::prixTtc());
    const remiseMaxPct = {{ Js::from(boutique()->remise_max_pct !== null ? (float) boutique()->remise_max_pct : null) }};
    const panier = new Map(); // id => { produit, quantite }
    const $ = (id) => document.getElementById(id);

    // Restaure le ticket après une erreur (stock insuffisant, etc.)
    (@json(old('lignes', [])) || []).forEach(l => {
        const p = catalogue.find(x => x.id == l.produit_id);
        if (p) panier.set(p.id, { produit: p, quantite: Number(l.quantite) || 1, cond: !!l.conditionnement && !!p.cond });
    });

    // Ventes hors connexion pas encore envoyées : leur marchandise est déjà sortie du rayon
    (window.HorsLigne?.file() || []).filter(v => !v.erreur).forEach(v => v.lignes.forEach(l => {
        const p = catalogue.find(x => x.id == l.produit_id);
        if (p) p.stock -= Number(l.quantite) * (l.conditionnement && p.qteCond ? p.qteCond : 1);
    }));

    function tuiles(liste) {
        $('tuiles').innerHTML = liste.slice(0, 60).map(p => `
            <div class="col-6 col-md-4 col-xxl-3">
              <button type="button" class="produit-tuile" data-id="${p.id}" ${p.stock <= 0 ? 'disabled' : ''}>
                <span class="nom">${echapper(p.nom)}</span>
                <span class="fw-bold montant">${p.promo ? `<s class="text-doux fw-normal small">${gnf(p.prix)}</s> <span class="text-danger">${gnf(p.promo)}</span>` : gnf(p.prix)}</span>
                <span class="small ${p.stock <= 0 ? 'text-danger' : 'text-doux'}">${p.stock <= 0 ? 'Rupture' : 'Stock : ' + formatQte(p.stock) + ' ' + echapper(p.unite)}</span>
              </button>
            </div>`).join('');
        $('aucun').classList.toggle('d-none', liste.length > 0);
    }

    function filtrer(terme) {
        const t = terme.trim().toLowerCase();
        return t ? catalogue.filter(p => p.nom.toLowerCase().includes(t) || (p.code && p.code.toLowerCase() === t)) : catalogue;
    }

    let attente;
    $('recherche').addEventListener('input', (e) => {
        const liste = filtrer(e.target.value);
        tuiles(liste);
        // Grand catalogue : on complète avec une recherche sur le serveur
        clearTimeout(attente);
        if (liste.length < 5 && e.target.value.trim().length >= 2) {
            attente = setTimeout(async () => {
                const r = await fetch(`{{ route('ventes.produits') }}?q=${encodeURIComponent(e.target.value)}`, { headers: { Accept: 'application/json' } });
                (await r.json()).forEach(p => {
                    if (!catalogue.some(c => c.id === p.id)) catalogue.push({ id: p.id, nom: p.designation, code: p.code_barre, prix: p.prix_vente, gros: p.prix_gros, qteGros: p.quantite_gros ? Number(p.quantite_gros) : null, stock: Number(p.stock), unite: p.unite,
                        cond: p.conditionnement && Number(p.qte_conditionnement) > 1 ? p.conditionnement : null, qteCond: Number(p.qte_conditionnement) || null,
                        prixCond: p.prix_conditionnement ? Number(p.prix_conditionnement) : Math.round(p.prix_vente * Number(p.qte_conditionnement || 0)),
                        promo: p.promo, promoCond: p.promo_cond, tva: Number(p.tva || 0), img: p.image || null });
                });
                tuiles(filtrer(e.target.value));
            }, 300);
        }
    });

    // Lecteur de code-barres : il « tape » le code puis Entrée
    $('recherche').addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const t = e.target.value.trim().toLowerCase();
        const exact = catalogue.find(p => p.code && p.code.toLowerCase() === t);
        const liste = filtrer(t);
        const choix = exact || (liste.length === 1 ? liste[0] : null);
        if (choix) { ajouter(choix); e.target.value = ''; tuiles(catalogue); }
    });

    // Scan par la caméra du téléphone (Chrome Android…) : pas besoin de lecteur, on enchaîne les articles
    (() => {
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) return;
        const bouton = $('scanCamera'), video = $('videoScan'), etat = $('etatScan'), modal = $('modalScan');
        bouton.classList.remove('d-none');
        let flux = null, boucle = null, dernier = '', dernierInstant = 0;
        const detecteur = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code'] });
        bouton.addEventListener('click', () => bootstrap.Modal.getOrCreateInstance(modal).show());
        modal.addEventListener('shown.bs.modal', async () => {
            try {
                flux = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
                video.srcObject = flux; await video.play();
                etat.textContent = 'Placez le code-barres dans le cadre.';
            } catch {
                etat.innerHTML = '<span class="text-danger">Caméra indisponible : autorisez-la dans le navigateur (le site doit être en https).</span>';
                return;
            }
            boucle = setInterval(async () => {
                if (video.readyState < 2) return;
                let codes = [];
                try { codes = await detecteur.detect(video); } catch { return; }
                const code = codes[0]?.rawValue?.trim();
                if (!code || (code === dernier && Date.now() - dernierInstant < 1500)) return; // même article encore devant l'objectif
                dernier = code; dernierInstant = Date.now();
                const produit = catalogue.find(p => p.code && p.code.toLowerCase() === code.toLowerCase());
                if (produit) {
                    ajouter(produit);
                    navigator.vibrate?.(80);
                    etat.innerHTML = `<span class="text-success fw-semibold">✓ ${echapper(produit.nom)} ajouté</span> — scannez l'article suivant.`;
                } else {
                    navigator.vibrate?.([60, 60, 60]);
                    etat.innerHTML = `<span class="text-danger">Code ${echapper(code)} inconnu : ajoutez-le sur la fiche du produit.</span>`;
                }
            }, 250);
        });
        modal.addEventListener('hidden.bs.modal', () => {
            clearInterval(boucle); boucle = null;
            flux?.getTracks().forEach(t => t.stop()); flux = null; video.srcObject = null;
            $('recherche').focus();
        });
    })();

    $('tuiles').addEventListener('click', (e) => {
        const b = e.target.closest('[data-id]');
        if (b) ajouter(catalogue.find(p => p.id == b.dataset.id));
    });

    // Quantité maximale vendable dans l'unité de la ligne (stock tenu à l'unité de base)
    const maxLigne = (l) => l.cond ? Math.floor(l.produit.stock / l.produit.qteCond) : l.produit.stock;

    function ajouter(p) {
        if (!p || p.stock <= 0) return;
        const l = panier.get(p.id);
        if (l) l.quantite = Math.min(l.quantite + 1, maxLigne(l)); else panier.set(p.id, { produit: p, quantite: 1, cond: false });
        rendre();
    }

    $('lignes').addEventListener('click', (e) => {
        const b = e.target.closest('button[data-action]');
        if (!b) return;
        const l = panier.get(Number(b.dataset.id));
        if (b.dataset.action === 'plus') l.quantite = Math.min(l.quantite + 1, maxLigne(l));
        if (b.dataset.action === 'moins') l.quantite = Math.max(l.quantite - 1, 0);
        // 12 bouteilles comptées une par une = 1 paquet : on passe au prix du paquet
        if (b.dataset.action === 'en-cond') { l.quantite = Math.round(l.quantite / l.produit.qteCond * 100) / 100; l.cond = true; }
        if (b.dataset.action === 'retirer' || l.quantite <= 0) panier.delete(l.produit.id);
        rendre();
    });
    $('lignes').addEventListener('change', (e) => {
        // Choix de l'unité : à l'unité ou au conditionnement (carton, casier…)
        if (e.target.matches('select[data-unite]')) {
            const l = panier.get(Number(e.target.dataset.unite));
            l.cond = e.target.value === 'cond';
            l.quantite = Math.max(Math.min(l.quantite, maxLigne(l)), 0);
            if (l.quantite <= 0) { panier.delete(l.produit.id); confirmer({ titre: 'Stock insuffisant', message: 'Pas assez de stock pour un ' + l.produit.cond + ' complet.', bouton: 'Compris' }); }
            rendre();
            return;
        }
        if (!e.target.matches('input[data-id]')) return;
        const l = panier.get(Number(e.target.dataset.id));
        const q = parseFloat(String(e.target.value).replace(',', '.'));
        if (!q || q <= 0) panier.delete(l.produit.id); else l.quantite = Math.min(q, maxLigne(l));
        rendre();
    });
    $('vider').addEventListener('click', async () => {
        if (!panier.size) return;
        const ok = await confirmer({ titre: 'Vider le ticket ?', message: 'Tous les produits du ticket en cours seront retirés.', bouton: 'Oui, vider', type: 'danger' });
        if (ok) { panier.clear(); rendre(); }
    });

    // Même règle que le serveur : prix de gros pour un client grossiste ou à partir de la quantité de gros
    const clientGrossiste = () => $('client_id').selectedOptions[0]?.dataset.grossiste === '1';
    const estGros = (l) => !l.cond && !!l.produit.gros && (clientGrossiste() || (l.produit.qteGros && l.quantite >= l.produit.qteGros));
    // Prix convenu avec le client choisi (vente à l'unité) : appliqué s'il est plus bas que le prix normal ou de gros
    const tarifsClients = @json((object) $tarifsClients);
    const prixConvenu = (l) => { const t = l.cond ? null : tarifsClients[$('client_id').value]?.[l.produit.id]; return t ? Number(t) : null; };
    const prixBase = (l) => l.cond ? l.produit.prixCond : (estGros(l) ? l.produit.gros : l.produit.prix);
    const avecPrixConvenu = (l) => prixConvenu(l) !== null && prixConvenu(l) < prixBase(l);
    const prixNormal = (l) => avecPrixConvenu(l) ? prixConvenu(l) : prixBase(l);
    const prixPromo = (l) => l.cond ? l.produit.promoCond : l.produit.promo;
    const enPromo = (l) => !!prixPromo(l) && prixPromo(l) < prixNormal(l);
    const prixUnit = (l) => enPromo(l) ? prixPromo(l) : prixNormal(l);
    // Vendu à l'unité alors que la quantité fait un paquet complet : proposer le prix du paquet (moins cher pour le client)
    function conseilCond(l) {
        const n = l.produit.qteCond;
        if (l.cond || !l.produit.cond || !n || l.quantite < n) return '';
        const paquets = l.quantite / n, prixPaquet = prixUnit({ ...l, cond: true, quantite: paquets });
        const economie = Math.round(prixUnit(l) * l.quantite - prixPaquet * paquets);
        if (economie <= 0) return '';
        const nom = echapper(l.produit.cond);
        return Math.abs(paquets - Math.round(paquets)) < 0.001
            ? `<div style="grid-column:1/-1"><button type="button" class="btn btn-sm btn-outline-success py-0" data-action="en-cond" data-id="${l.produit.id}">
                <i class="bi bi-box2 me-1"></i>${formatQte(l.quantite)} × ${echapper(l.produit.unite)} → ${formatQte(paquets)} ${nom} de ${formatQte(n)} : prix du ${nom} (− ${gnf(economie)})</button></div>`
            : `<div class="small text-success" style="grid-column:1/-1"><i class="bi bi-lightbulb me-1"></i>1 ${nom} de ${formatQte(n)} = ${gnf(prixUnit({ ...l, cond: true, quantite: 1 }))} : par multiple de ${formatQte(n)}, le ${nom} revient moins cher.</div>`;
    }

    function totaux() {
        const sousTotal = [...panier.values()].reduce((s, l) => s + Math.round(prixUnit(l) * l.quantite), 0);
        const remise = Math.min(nombre($('remise')?.value), sousTotal);
        // TVA multi-taux : chaque produit a son taux (exonéré = 0) ; la remise est répartie au prorata, comme au serveur
        const bases = {};
        [...panier.values()].forEach(l => {
            const ligne = Math.round(prixUnit(l) * l.quantite);
            const taux = Number(l.produit.tva ?? tauxTva);
            bases[taux] = (bases[taux] || 0) + ligne - (sousTotal ? ligne * remise / sousTotal : 0);
        });
        // Prix TTC : la TVA est comprise dans les prix (on l'extrait, le client paie le prix affiché) ; sinon elle s'ajoute
        const tva = Object.entries(bases).reduce((s, [taux, base]) => s + Math.round(prixTtc ? base * Number(taux) / (100 + Number(taux)) : base * Number(taux) / 100), 0);
        return { sousTotal, remise, tva, total: prixTtc ? sousTotal - remise : sousTotal - remise + tva };
    }

    // Écran client : le ticket est transmis à l'écran tourné vers le client (même navigateur, sans serveur)
    const canalEcran = !@json($proforma) && 'BroadcastChannel' in window ? new BroadcastChannel('gn-ecran-client-{{ boutique()->id }}-{{ auth()->id() }}') : null;
    let dernierTicket = null;
    function diffuserEcranClient(t, lignes, recu, du, deduit = 0) {
        if (!canalEcran) return;
        const opt = $('client_id').selectedOptions[0];
        dernierTicket = {
            client: $('client_id').value ? opt.text.split('—')[0].trim() : '',
            lignes: lignes.map(l => ({ cle: l.produit.id + (l.cond ? 'c' : ''), nom: l.produit.nom + (l.cond ? ' (' + l.produit.cond + ')' : ''),
                quantite: formatQte(l.quantite), prix: prixUnit(l), total: Math.round(prixUnit(l) * l.quantite), promo: enPromo(l), img: l.produit.img || null })),
            sousTotal: t.sousTotal, remise: t.remise, tva: t.tva, total: t.total, deduit, resteAPayer: du,
            recu: $('mode').value === 'especes' && $('montant_recu').value.trim() !== '' ? recu : 0,
            monnaie: $('mode').value === 'especes' && $('montant_recu').value.trim() !== '' ? Math.max(0, recu - du) : 0,
        };
        canalEcran.postMessage({ type: 'ticket', ticket: dernierTicket });
    }
    canalEcran?.addEventListener('message', ({ data }) => { if (data?.type === 'demande') rendre(); });

    let soldeCarte = 0, messageCarte = '';   // carte cadeau vérifiée (voir plus bas)
    function rendre() {
        const lignes = [...panier.values()];
        $('ticketVide').classList.toggle('d-none', lignes.length > 0);
        // Quitter d'abord le champ quantité en cours de saisie : sa validation (blur) ne doit pas survenir pendant qu'on le retire
        if ($('lignes').contains(document.activeElement)) document.activeElement.blur();
        $('lignes').querySelectorAll('.ligne-ticket').forEach(n => n.remove());
        $('lignes').insertAdjacentHTML('beforeend', lignes.map(l => `
            <div class="ligne-ticket">
              <div class="fw-semibold">${echapper(l.produit.nom)}</div>
              <div class="text-end fw-semibold montant">${gnf(prixUnit(l) * l.quantite)}</div>
              <div class="d-flex align-items-center gap-2">
                <span class="qte-ctrl">
                  <button type="button" data-action="moins" data-id="${l.produit.id}" aria-label="Moins">−</button>
                  <input value="${formatQte(l.quantite)}" data-id="${l.produit.id}" inputmode="decimal" aria-label="Quantité">
                  <button type="button" data-action="plus" data-id="${l.produit.id}" aria-label="Plus">+</button>
                </span>
                ${l.produit.cond ? `<select class="form-select form-select-sm py-0" style="width:auto" data-unite="${l.produit.id}" aria-label="Unité de vente">
                    <option value="unite" ${l.cond ? '' : 'selected'}>${echapper(l.produit.unite)}</option>
                    <option value="cond" ${l.cond ? 'selected' : ''}>${echapper(l.produit.cond)} de ${formatQte(l.produit.qteCond)}</option></select>` : ''}
                <small class="text-doux">× ${gnf(prixUnit(l))}</small>
                ${enPromo(l) ? '<span class="etat etat-rupture">promo</span>' : ''}
                ${avecPrixConvenu(l) && !enPromo(l) ? '<span class="etat etat-ok">prix convenu</span>' : ''}
                ${estGros(l) && !enPromo(l) && !avecPrixConvenu(l) ? '<span class="etat etat-ok">prix de gros</span>' : (!l.cond && l.produit.gros && l.produit.qteGros ? `<small class="text-doux">gros dès ${formatQte(l.produit.qteGros)}</small>` : '')}
              </div>
              ${conseilCond(l)}
              <div class="text-end"><button type="button" class="btn btn-sm btn-link text-danger p-0" data-action="retirer" data-id="${l.produit.id}">Retirer</button></div>
            </div>`).join(''));

        $('champsLignes').innerHTML = lignes.map((l, i) =>
            `<input type="hidden" name="lignes[${i}][produit_id]" value="${l.produit.id}"><input type="hidden" name="lignes[${i}][quantite]" value="${l.quantite}">`
            + (l.cond ? `<input type="hidden" name="lignes[${i}][conditionnement]" value="1">` : '')).join('');

        const t = totaux();
        $('sousTotal').textContent = gnf(t.sousTotal);
        // Remise maximale fixée dans les paramètres : prévenu avant de valider (le serveur la vérifie aussi)
        if ($('alerteRemise')) {
            const maxRemise = remiseMaxPct === null ? null : Math.floor(t.sousTotal * remiseMaxPct / 100);
            const trop = maxRemise !== null && t.remise > maxRemise;
            $('alerteRemise').classList.toggle('d-none', !trop);
            $('alerteRemise').textContent = trop ? `Remise trop élevée : ${gnf(maxRemise)} au maximum.` : '';
        }
        if ($('tva')) $('tva').textContent = gnf(t.tva);
        $('total').textContent = gnf(t.total);
        $('totalMobile').textContent = gnf(t.total);
        $('nbArticles').textContent = lignes.length;
        $('valider').disabled = lignes.length === 0;
        if ($('enDevis')) $('enDevis').disabled = lignes.length === 0;
        if ($('enAttente')) $('enAttente').disabled = lignes.length === 0;

        // Points de fidélité : payés en premier, le montant reçu couvre le reste
        const soldePoints = Number($('client_id').selectedOptions[0]?.dataset.points || 0);
        const pointsOk = $('blocPoints') && soldePoints > 0 && soldePoints >= {{ (int) boutique()->fidelite_minimum }};
        if ($('blocPoints')) {
            $('blocPoints').classList.toggle('d-none', !pointsOk);
            $('soldePoints').textContent = soldePoints.toLocaleString('fr-FR');
            if (!pointsOk) $('utiliser_points').checked = false;
        }
        const points = pointsOk && $('utiliser_points').checked ? Math.min(soldePoints, t.total) : 0;
        if ($('payePoints')) {
            $('payePoints').classList.toggle('d-none', !points);
            $('payePoints').textContent = points ? `Payé en points : ${gnf(points)} — reste ${gnf(t.total - points)}` : '';
        }
        // Anniversaire du client : un repère pour le vendeur (souhaiter, cadeau prévu par la boutique)
        const anniv = $('client_id').value ? $('client_id').selectedOptions[0]?.dataset.anniv : '';
        $('annivClient').classList.toggle('d-none', anniv === '' || anniv === undefined || Number(anniv) > 6);
        const cadeauAnniv = @json(boutique()->cadeau_anniversaire);
        $('annivClient').textContent = anniv === '0' ? "🎂 C'est son anniversaire aujourd'hui !" + (cadeauAnniv ? ' Cadeau : ' + cadeauAnniv : '')
            : (anniv !== '' && Number(anniv) <= 6 ? `🎂 Anniversaire dans ${anniv} jour${Number(anniv) > 1 ? 's' : ''}.` : '');
        // Avoir du client (retour précédent) : proposé d'office dès qu'il en a un, utilisé après les points
        const soldeAvoir = !@json($proforma) && $('client_id').value ? Number($('client_id').selectedOptions[0]?.dataset.avoir || 0) : 0;
        $('blocAvoir').classList.toggle('d-none', soldeAvoir <= 0);
        $('soldeAvoir').textContent = gnf(soldeAvoir);
        let avoir = soldeAvoir > 0 && $('utiliser_avoir').checked ? Math.min(soldeAvoir, t.total - points) : 0;
        $('payeAvoir').classList.toggle('d-none', !avoir);
        $('payeAvoir').textContent = avoir ? `Payé avec l'avoir : ${gnf(avoir)} — reste ${gnf(t.total - points - avoir)}` : '';
        // Carte cadeau vérifiée : utilisée après l'avoir, jamais au-delà de son solde
        const carte = soldeCarte > 0 ? Math.min(soldeCarte, Math.max(0, t.total - points - avoir)) : 0;
        if (soldeCarte > 0) {
            $('etatCarte').innerHTML = `<span class="text-success fw-semibold">${echapper(messageCarte)}</span>`
                + (carte ? `<br><span class="text-success">Payé avec la carte : ${gnf(carte)} — reste ${gnf(t.total - points - avoir - carte)}</span>` : '');
        }
        avoir += carte;   // la suite du calcul traite la carte comme un règlement déjà fait
        // Bon d'échange : paie ce qui reste ; s'il vaut plus que les nouveaux articles, la différence est rendue en espèces
        if ($('blocEchange')) {
            const soldeBon = Number($('blocEchange').dataset.solde);
            const parBon = Math.min(soldeBon, Math.max(0, t.total - points - avoir));
            const resteBon = soldeBon - parBon;
            $('etatEchange').innerHTML = !lignes.length ? 'Ajoutez les articles que le client prend à la place.'
                : `Payé avec le bon : ${gnf(parBon)}` + (resteBon > 0 ? `<br><span class="text-warning-emphasis">Reste du bon rendu en espèces au client : ${gnf(resteBon)}</span>`
                    : (t.total - points - avoir - parBon > 0 ? ` — le client paie la différence : ${gnf(t.total - points - avoir - parBon)}` : ''));
            avoir += parBon;
        }
        // Autres moyens (paiement mixte) : déduits avant le moyen principal
        let autres = 0;
        document.querySelectorAll('.autre-paiement').forEach((row, i) => {
            row.querySelectorAll('[data-champ]').forEach(el => el.name = `paiements_autres[${i}][${el.dataset.champ}]`);
            autres += nombre(row.querySelector('[data-champ=montant]').value);
        });
        const du = Math.max(0, t.total - points - avoir - autres);
        if (autres > t.total - points - avoir) {
            $('info').innerHTML = '<span class="text-danger fw-semibold">Les autres paiements dépassent le total à payer.</span>';
        }
        $('montant_recu').placeholder = points || avoir ? '= ' + gnf(du) : '= total';
        const recu = $('montant_recu').value.trim() === '' ? du : nombre($('montant_recu').value);
        // Échéance proposée seulement quand il reste un crédit pour un client identifié
        $('blocEcheance').classList.toggle('d-none', !(lignes.length && recu < du && $('client_id').value));
        diffuserEcranClient(t, lignes, recu, du, points + avoir);
        const info = $('info');
        if (!lignes.length) info.innerHTML = '';
        else if (autres > t.total - points - avoir) return;
        else if (recu > du) info.innerHTML = `<span class="text-success fw-semibold">Monnaie à rendre : ${gnf(recu - du)}</span>`;
        else if (recu < du) {
            // Règles de crédit des paramètres : plafond (par client ou par défaut) et délai de remboursement
            const opt = $('client_id').selectedOptions[0];
            let regle = '';
            if (!$('client_id').value) regle = 'Choisissez un client pour une vente à crédit.';
            else if (opt.dataset.retard === '1') regle = `Ce client a un crédit en retard (échéance dépassée) : encaissez-le ou reportez son échéance avant un nouveau crédit.`;
            else if (opt.dataset.plafond !== '' && Number(opt.dataset.du) + (du - recu) > Number(opt.dataset.plafond)) {
                regle = `Plafond de crédit dépassé : il doit déjà ${gnf(Number(opt.dataset.du))}, plafond ${gnf(Number(opt.dataset.plafond))}.`;
            }
            const dejaDu = $('client_id').value ? Number(opt.dataset.du || 0) : 0;
            info.innerHTML = `<span class="text-warning-emphasis fw-semibold">Reste à crédit : ${gnf(du - recu)}</span>`
                + (regle ? `<br><span class="text-danger">${regle}</span>`
                    : dejaDu > 0 ? `<br><span class="small text-doux">Il doit déjà ${gnf(dejaDu)} : sa dette passera à ${gnf(dejaDu + du - recu)}.</span>` : '');
        }
        else info.innerHTML = '';
    }

    ['montant_recu', 'remise', 'client_id'].forEach(id => $(id)?.addEventListener('input', rendre));
    $('client_id').addEventListener('change', rendre);
    $('utiliser_points')?.addEventListener('change', rendre);
    $('utiliser_avoir').addEventListener('change', rendre);

    // Carte cadeau : vérification du code auprès du serveur (solde, validité), puis utilisée au calcul du reste à payer
    function oublierCarte(message = '') {
        soldeCarte = 0; messageCarte = '';
        $('etatCarte').innerHTML = message;
        rendre();
    }
    $('ouvrirCarte').addEventListener('click', () => {
        $('blocCarte').classList.remove('d-none');
        $('ouvrirCarte').classList.add('d-none');
        $('carte_cadeau').focus();
    });
    $('retirerCarte').addEventListener('click', () => {
        $('carte_cadeau').value = '';
        $('blocCarte').classList.add('d-none');
        $('ouvrirCarte').classList.remove('d-none');
        oublierCarte();
    });
    $('carte_cadeau').addEventListener('input', () => { if (soldeCarte) oublierCarte(); });
    $('carte_cadeau').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('verifierCarte').click(); } });
    $('verifierCarte').addEventListener('click', async () => {
        const code = $('carte_cadeau').value.trim();
        if (!code) return $('carte_cadeau').focus();
        $('etatCarte').innerHTML = '<span class="text-doux">Vérification…</span>';
        try {
            const r = await fetch($('carte_cadeau').dataset.url + '?code=' + encodeURIComponent(code), { headers: { Accept: 'application/json' } });
            if (r.status === 429) return oublierCarte('<span class="text-danger">Trop d’essais : patientez une minute.</span>');
            const d = await r.json();
            if (!d.ok) return oublierCarte(`<span class="text-danger fw-semibold">${echapper(d.message)}</span>`);
            $('carte_cadeau').value = d.code;
            soldeCarte = d.solde; messageCarte = d.message;
            rendre();
        } catch (e) {
            oublierCarte('<span class="text-danger">Vérification impossible : vérifiez la connexion.</span>');
        }
    });
    $('ajouterPaiement').addEventListener('click', () => {
        $('autresPaiements').append($('modeleAutrePaiement').content.cloneNode(true));
        rendre();
        $('autresPaiements').lastElementChild.querySelector('[data-champ=montant]').focus();
    });
    $('autresPaiements').addEventListener('input', rendre);
    $('autresPaiements').addEventListener('click', e => {
        if (e.target.closest('.retirer-paiement')) { e.target.closest('.autre-paiement').remove(); rendre(); }
    });
    $('mode').addEventListener('change', () => { $('blocReference').classList.toggle('d-none', $('mode').value === 'especes'); rendre(); });
    $('mode').dispatchEvent(new Event('change'));
    // Connexion : indicateur, et vente gardée sur l'appareil si le serveur ne répond pas
    const majReseau = () => {
        const ok = navigator.onLine;
        $('reseau').className = 'indicateur-reseau align-middle ' + (ok ? 'en-ligne' : 'hors-ligne');
        $('reseau').innerHTML = ok ? '<i class="bi bi-wifi"></i><span>En ligne</span>' : '<i class="bi bi-wifi-off"></i><span>Hors connexion</span>';
    };
    window.addEventListener('online', majReseau);
    window.addEventListener('offline', majReseau);
    majReseau();

    const bloquerBoutons = (etat) => ['valider', 'enDevis', 'enAttente'].forEach(id => { if ($(id)) $(id).disabled = etat; });
    let envoiNatif = false;
    $('formVente').addEventListener('submit', async (e) => {
        if (envoiNatif) return;
        // En mode proforma, le bouton principal enregistre une proforma (pas une vente) : jamais hors connexion
        const principal = !@json($proforma) && (!e.submitter || e.submitter.id === 'valider');
        e.preventDefault();
        bloquerBoutons(true);
        const etat = window.HorsLigne ? await HorsLigne.etatServeur() : 'ok';
        if (etat === 'ok') {
            // Serveur joignable : envoi normal (le bouton cliqué garde son action : vente, devis ou attente)
            envoiNatif = true;
            bloquerBoutons(false);
            if (e.submitter) $('formVente').requestSubmit(e.submitter); else $('formVente').requestSubmit();
            setTimeout(() => bloquerBoutons(true), 0);
            return;
        }
        if (document.body.dataset.horsLigne !== '1') {
            bloquerBoutons(false);
            return confirmer({ titre: 'Pas de connexion', message: etat === 'session' ? 'Votre session a expiré : reconnectez-vous.'
                : 'Le serveur ne répond pas. La caisse sans connexion n\'est pas incluse dans votre formule : réessayez dans un instant.', bouton: 'Compris' });
        }
        if (!principal) {
            bloquerBoutons(false);
            return confirmer({ titre: 'Pas de connexion', message: 'Devis et tickets en attente ont besoin de la connexion. Seule la vente payée fonctionne hors connexion.', bouton: 'Compris' });
        }
        venteHorsLigne(etat);
    });

    function venteHorsLigne(etat) {
        const t = totaux();
        const erreur = (m) => { bloquerBoutons(false); $('info').innerHTML = `<span class="text-danger fw-semibold">${m}</span>`; };
        if ($('utiliser_points')?.checked) return erreur('Les points de fidélité ne sont pas utilisables hors connexion.');
        if ($('carte_cadeau').value.trim()) return erreur('Les cartes cadeaux ne sont pas utilisables hors connexion.');
        if (!$('blocAvoir').classList.contains('d-none') && $('utiliser_avoir').checked) return erreur('L\'avoir du client n\'est pas utilisable hors connexion.');
        if ($('blocEchange')) return erreur('Un échange a besoin de la connexion : réessayez dès qu\'elle revient.');
        const autres = [...document.querySelectorAll('.autre-paiement')].map(r => ({
            mode: r.querySelector('[data-champ=mode]').value, montant: nombre(r.querySelector('[data-champ=montant]').value),
            reference: r.querySelector('[data-champ=reference]').value || null })).filter(p => p.montant > 0);
        const totalAutres = autres.reduce((s, p) => s + p.montant, 0);
        const recu = $('montant_recu').value.trim() === '' ? t.total - totalAutres : nombre($('montant_recu').value);
        if (totalAutres + recu < t.total) return erreur('Hors connexion, la vente doit être payée entièrement : le crédit n\'est pas possible.');

        const vente = {
            uuid: HorsLigne.uuid(), cree_le: new Date().toISOString(), client_id: $('client_id').value || null,
            client_nom: $('client_id').value ? $('client_id').selectedOptions[0].text : null,
            lignes: [...panier.values()].map(l => ({ produit_id: l.produit.id, quantite: l.quantite, conditionnement: l.cond ? 1 : 0, prix_unitaire: prixUnit(l),
                nom: l.produit.nom + (l.cond ? ' (' + l.produit.cond + ')' : '') })),
            remise: t.remise, mode: $('mode').value, montant_recu: recu, reference: $('reference')?.value || null,
            paiements_autres: autres, total_affiche: t.total,
        };
        if (!HorsLigne.ajouter(vente)) return erreur('Mémoire de l\'appareil pleine : impossible de garder la vente. Notez-la sur papier.');

        // La marchandise est partie : on met à jour le stock affiché, on imprime un reçu provisoire, on vide le ticket
        vente.lignes.forEach(l => { const p = catalogue.find(x => x.id === l.produit_id); if (p) p.stock -= l.quantite * (l.conditionnement && p.qteCond ? p.qteCond : 1); });
        recuProvisoire(vente, recu + totalAutres - t.total);
        panier.clear();
        $('montant_recu').value = '';
        $('autresPaiements').innerHTML = '';
        if ($('remise')) $('remise').value = '';
        tuiles(filtrer($('recherche').value));
        rendre();
        bloquerBoutons(false);
        $('info').innerHTML = `<span class="text-success fw-semibold"><i class="bi bi-cloud-slash me-1"></i>Vente de ${gnf(t.total)} gardée sur l'appareil`
            + (etat === 'session' ? ' (session expirée : reconnectez-vous pour l\'envoyer).' : ' : elle sera envoyée au retour de la connexion.') + '</span>';
    }

    function recuProvisoire(v, rendu) {
        const w = window.open('', '_blank', 'width=380,height=640');
        if (!w) return;
        const ligne = (a, b) => `<tr><td>${echapper(a)}</td><td style="text-align:right">${b}</td></tr>`;
        w.document.write(`<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Reçu provisoire</title>
            <style>body{font-family:monospace;font-size:12px;width:72mm;margin:0 auto}table{width:100%;border-collapse:collapse}.c{text-align:center}.sep{border-top:1px dashed #000;margin:6px 0}</style></head><body>
            <div class="c"><strong>${echapper(@json(boutique()->nom))}</strong><br>${ {{ Js::from(boutique()->coordonnees()) }}.map(echapper).join('<br>') }
                <br>REÇU PROVISOIRE<br>(vente hors connexion)</div><div class="sep"></div>
            <div>${new Date(v.cree_le).toLocaleString('fr-FR')}</div>${v.client_nom ? `<div>Client : ${echapper(v.client_nom)}</div>` : ''}<div class="sep"></div>
            <table>${v.lignes.map(l => ligne(`${l.nom} × ${l.quantite}`, gnf(l.prix_unitaire * l.quantite))).join('')}</table><div class="sep"></div>
            <table>${v.remise ? ligne('Remise', '-' + gnf(v.remise)) : ''}${ligne('TOTAL', '<strong>' + gnf(v.total_affiche) + '</strong>')}
            ${v.paiements_autres.map(p => ligne(p.mode, gnf(p.montant))).join('')}${ligne($('mode').selectedOptions[0].text, gnf(v.montant_recu))}
            ${rendu > 0 ? ligne('Rendu', gnf(rendu)) : ''}</table><div class="sep"></div>
            <div class="c">Réf. ${v.uuid.slice(0, 8).toUpperCase()}<br>Le numéro définitif sera attribué à l'envoi.
                <br><br>${echapper({{ Js::from(boutique()->pied_facture ?: 'Merci de votre visite !') }})}</div>
            <script>window.onload = () => window.print();<\/script></body></html>`);
        w.document.close();
    }

    // Recherche d'un client absent de la liste (boutique avec beaucoup de clients) : il y est ajouté avec sa dette, ses points…
    (() => {
        const champ = $('rechercheClient'), boite = $('resultatsClients');
        if (!champ) return;
        let minuterie = null, derniere = '';
        const fermer = () => { boite.classList.add('d-none'); boite.innerHTML = ''; };
        const choisir = (c) => {
            let opt = [...$('client_id').options].find(o => o.value == c.id);
            if (!opt) {
                opt = new Option(c.libelle, c.id);
                tarifsClients[c.id] = c.tarifs || {};
                Object.entries({ grossiste: c.grossiste, points: c.points, avoir: c.avoir, anniv: c.anniv, du: c.du, plafond: c.plafond, retard: c.retard })
                    .forEach(([k, v]) => opt.dataset[k] = v);
                $('client_id').add(opt);
            }
            $('client_id').value = c.id;
            $('client_id').dispatchEvent(new Event('change', { bubbles: true }));
            champ.value = ''; fermer();
        };
        champ.addEventListener('input', () => {
            clearTimeout(minuterie);
            const q = champ.value.trim();
            if (q.length < 2) return fermer();
            minuterie = setTimeout(async () => {
                derniere = q;
                try {
                    const r = await fetch(champ.dataset.url + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                    const liste = r.ok ? await r.json() : [];
                    if (q !== derniere) return;   // une saisie plus récente a pris le relais
                    boite.innerHTML = '';
                    if (!liste.length) {
                        boite.innerHTML = '<div class="list-group-item small text-doux">Aucun client trouvé.</div>';
                    }
                    liste.forEach((c) => {
                        const b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'list-group-item list-group-item-action small';
                        b.textContent = c.libelle;
                        if (c.du > 0) {
                            const s = document.createElement('span');
                            s.className = 'text-danger ms-1';
                            s.textContent = '· doit ' + gnf(c.du);
                            b.append(s);
                        }
                        b.addEventListener('click', () => choisir(c));
                        boite.append(b);
                    });
                    boite.classList.remove('d-none');
                } catch (e) {
                    boite.innerHTML = '<div class="list-group-item small text-danger">Recherche impossible : vérifiez la connexion.</div>';
                    boite.classList.remove('d-none');
                }
            }, 250);
        });
        champ.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { champ.value = ''; fermer(); }
            if (e.key === 'Enter') { e.preventDefault(); boite.querySelector('button')?.click(); }
        });
        document.addEventListener('click', (e) => { if (!e.target.closest('#resultatsClients, #rechercheClient')) fermer(); });
    })();

    // Création rapide d'un client sans quitter la caisse
    $('formClient')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const r = await fetch(`{{ route('clients.store') }}`, {
            method: 'POST', body: new FormData(e.target),
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        const d = await r.json();
        if (!r.ok) { $('erreurClient').textContent = d.message || 'Vérifiez les champs.'; return; }
        $('client_id').add(new Option(d.nom + (d.telephone ? ' — ' + d.telephone : ''), d.id, true, true));
        bootstrap.Modal.getInstance($('modalClient')).hide();
        e.target.reset();
        rendre();
    });

    function formatQte(q) { return Number(q).toLocaleString('fr-FR', { maximumFractionDigits: 2 }); }
    function echapper(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

    tuiles(catalogue);
    rendre();
})();
</script>
@endpush
