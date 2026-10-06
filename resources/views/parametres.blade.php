@extends('layouts.app')
@section('titre', 'Paramètres de la boutique')
@section('contenu')
    <div class="entete-page"><h1>Paramètres de la boutique</h1></div>
    <form method="post" action="{{ route('parametres.update') }}" enctype="multipart/form-data" class="row g-4">
        @csrf @method('put')
        <div class="col-lg-4">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Logo et couleurs</h2></div>
                <div class="bloc-corps">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        @if ($boutique->logoTeleverseUrl())
                            <img src="{{ $boutique->logoTeleverseUrl() }}" alt="Logo actuel" style="width:88px;height:88px;object-fit:contain" class="border rounded p-1 bg-white">
                        @else
                            <div class="barre-initiales" style="width:88px;height:88px;font-size:1.6rem">{{ $boutique->initiales() }}</div>
                        @endif
                        <div class="small text-doux">Le logo apparaît dans le menu, l'onglet du navigateur, l'écran de connexion, les factures, les reçus et tous les documents exportés (PDF et Excel).</div>
                    </div>
                    @if ($boutique->logo && ! $boutique->licencePayee())
                        <div class="alert alert-warning small py-2"><i class="bi bi-lock me-1"></i>
                            Votre logo est bien enregistré. Il s'affichera partout (menu, onglet, connexion, factures, exports)
                            dès que votre licence sera payée. État actuel : <strong>{{ $boutique->libelleStatut() }}</strong>.</div>
                    @endif
                    <label class="form-label" for="logo">Nouveau logo (PNG ou JPG, 1 Mo max)</label>
                    <input type="file" name="logo" id="logo" accept="image/png,image/jpeg,image/webp" class="form-control">
                    @if ($boutique->logo)
                        <div class="form-check mt-2">
                            <input type="checkbox" name="supprimer_logo" value="1" id="supprimer_logo" class="form-check-input">
                            <label for="supprimer_logo" class="form-check-label">Retirer le logo</label>
                        </div>
                    @endif
                    @php
                        $couleurs = [
                            ['couleur', 'Principale', old('couleur', $boutique->couleur), 'Boutons, menu actif, en-têtes des factures'],
                            ['couleur_2', 'Secondaire', old('couleur_2', $boutique->couleur_2), 'Menu latéral, blocs de totaux, bandeau des documents'],
                            ['couleur_3', 'Accent', old('couleur_3', $boutique->couleur_3), 'Filets, soulignements et détails'],
                        ];
                    @endphp
                    <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                        <span class="form-label mb-0">Couleurs de l'entreprise <span class="text-doux small">(jusqu'à 3)</span></span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="ajouterCouleur"><i class="bi bi-plus-lg me-1"></i>Ajouter une couleur</button>
                    </div>
                    <div class="nuancier" id="nuancier">
                        @foreach ($couleurs as $i => [$champ, $libelle, $valeur, $usage])
                            <div class="nuance {{ $i && ! $valeur ? 'd-none' : '' }}" data-nuance="{{ $i }}" title="{{ $usage }}">
                                <input type="color" name="{{ $champ }}" id="{{ $champ }}" value="{{ $valeur ?: ($i === 1 ? '#14302A' : '#F2B233') }}"
                                       class="form-control form-control-color" aria-label="Couleur {{ strtolower($libelle) }}" @disabled($i && ! $valeur)>
                                <label for="{{ $champ }}" class="small text-doux">{{ $libelle }}</label>
                                @if ($i)<button type="button" class="retirer" data-retirer aria-label="Retirer la couleur {{ strtolower($libelle) }}"><i class="bi bi-x"></i></button>@endif
                            </div>
                        @endforeach
                    </div>
                    <div class="apercu-charte mt-3" id="apercuCharte" aria-hidden="true"></div>
                    <ul class="small text-doux mt-2 mb-0 ps-3">
                        <li><strong>Principale</strong> : boutons, menu actif, en-têtes des factures.</li>
                        <li><strong>Secondaire</strong> : menu latéral et blocs de totaux (assombrie automatiquement pour rester lisible).</li>
                        <li><strong>Accent</strong> : filets et détails de l'interface et des documents.</li>
                    </ul>
                </div>
            </div>
            <div class="bloc mt-4">
                <div class="bloc-entete"><h2 class="mb-0">Abonnement</h2></div>
                <div class="bloc-corps">
                    <div class="d-flex justify-content-between"><span class="text-doux">Formule</span><strong>{{ $boutique->plan?->nom ?? '—' }}</strong></div>
                    <div class="d-flex justify-content-between mt-2"><span class="text-doux">État</span><strong>{{ $boutique->libelleStatut() }}</strong></div>
                    @if ($boutique->abonnement_expire_le)
                        <div class="d-flex justify-content-between mt-2"><span class="text-doux">Échéance</span><strong>{{ $boutique->abonnement_expire_le->format('d/m/Y') }}</strong></div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Coordonnées affichées sur les factures</h2></div>
                <div class="bloc-corps row g-3">
                    <div class="col-12"><label class="form-label" for="nom">Nom de la boutique</label>
                        <input name="nom" id="nom" value="{{ old('nom', $boutique->nom) }}" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label" for="telephone">Téléphone(s)</label>
                        <input name="telephone" id="telephone" value="{{ old('telephone', $boutique->telephone) }}" class="form-control"></div>
                    <div class="col-sm-6"><label class="form-label" for="email">E-mail</label>
                        <input type="email" name="email" id="email" value="{{ old('email', $boutique->email) }}" class="form-control"></div>
                    <div class="col-sm-8"><label class="form-label" for="adresse">Adresse</label>
                        <input name="adresse" id="adresse" value="{{ old('adresse', $boutique->adresse) }}" class="form-control" placeholder="Quartier, commune"></div>
                    <div class="col-sm-4"><label class="form-label" for="ville">Ville</label>
                        <input name="ville" id="ville" value="{{ old('ville', $boutique->ville) }}" class="form-control"></div>
                    <div class="col-sm-6"><label class="form-label" for="rccm">RCCM</label>
                        <input name="rccm" id="rccm" value="{{ old('rccm', $boutique->rccm) }}" class="form-control"></div>
                    <div class="col-sm-6"><label class="form-label" for="nif">NIF</label>
                        <input name="nif" id="nif" value="{{ old('nif', $boutique->nif) }}" class="form-control"></div>
                    <div class="col-12"><label class="form-label" for="pied_facture">Mention en bas des factures</label>
                        <textarea name="pied_facture" id="pied_facture" rows="2" class="form-control" placeholder="Ex. : Les marchandises vendues ne sont ni reprises ni échangées.">{{ old('pied_facture', $boutique->pied_facture) }}</textarea></div>
                </div>
            </div>
            <div class="bloc mt-4">
                <div class="bloc-entete"><h2 class="mb-0">TVA</h2></div>
                <div class="bloc-corps row g-3 align-items-end">
                    <div class="col-sm-6">
                        <div class="form-check form-switch">
                            <input type="checkbox" name="tva_active" value="1" id="tva_active" class="form-check-input" @checked(old('tva_active', $boutique->tva_active))>
                            <label for="tva_active" class="form-check-label">Ma boutique facture la TVA</label>
                        </div>
                        <div class="form-text">Laissez désactivé si vous n'êtes pas assujetti.</div>
                    </div>
                    <div class="col-sm-3"><label class="form-label" for="tva_taux">Taux (%)</label>
                        <input type="number" step="0.01" name="tva_taux" id="tva_taux" value="{{ old('tva_taux', $boutique->tva_taux) }}" class="form-control"></div>
                    {{-- Prix hors taxe (la TVA s'ajoute) ou TTC (la TVA est comprise dans le prix affiché) --}}
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="checkbox" name="prix_ttc" value="1" id="prix_ttc" class="form-check-input" @checked(old('prix_ttc', $boutique->prix_ttc))>
                            <label for="prix_ttc" class="form-check-label">Mes prix de vente sont <strong>TVA comprise (TTC)</strong></label>
                        </div>
                        <div class="form-text">Cochez si vos étiquettes affichent déjà le prix payé par le client. Les ventes déjà faites ne changent pas.</div>
                        {{-- Changement de mode : proposer d'ajuster les prix pour que les clients paient le même montant --}}
                        <div class="alert alert-warning small mt-2 mb-0 d-none" id="blocAjusterPrix" data-mode-initial="{{ $boutique->prix_ttc ? 1 : 0 }}">
                            <div class="form-check mb-1">
                                <input type="checkbox" name="ajuster_prix" value="1" id="ajuster_prix" class="form-check-input" checked>
                                <label for="ajuster_prix" class="form-check-label fw-semibold">Ajuster mes prix de vente pour que mes clients paient le même montant</label>
                            </div>
                            <span data-sens="ttc">Vos prix actuels sont hors taxe : ils seront multipliés par 1 + taux (avec 18 %, 10 000 devient 11 800). Sans ajustement, vos clients paieraient environ 15 % de moins.</span>
                            <span data-sens="ht">Vos prix actuels sont TVA comprise : ils seront divisés par 1 + taux (avec 18 %, 11 800 devient 10 000). Sans ajustement, vos clients paieraient 18 % de plus.</span>
                            Prix de gros, par conditionnement et promotions à prix fixe compris ; les produits exonérés ne changent pas.
                        </div>
                    </div>
                    {{-- Comment la TVA s'applique : réglage propre à cette boutique --}}
                    <div class="col-12">
                        @php
                            $tauxTexte = \App\Support\Tva::libelle((float) $boutique->tva_taux);
                            $tauxTexte = $tauxTexte === 'Exonéré' ? '0' : rtrim($tauxTexte, ' %');
                        @endphp
                        <div class="alert alert-info small mb-0" id="explicationTva">
                            <i class="bi bi-info-circle me-1"></i>Ce réglage ne concerne que <strong>{{ $boutique->nom }}</strong>@if ($boutique->reseau()->count() > 1) (chaque point de vente du réseau a le sien)@endif.
                            <span data-mode="ht" @class(['d-none' => $boutique->prix_ttc])>Vos prix de vente sont <strong>hors taxe</strong> : la TVA s'y <strong>ajoute</strong> à la caisse, sur les devis et les factures.
                                Exemple avec <span data-taux-exemple>{{ $tauxTexte }}</span> % : un article à 10 000 GNF est encaissé <strong data-ttc-exemple>{{ number_format((int) round(10000 * (1 + (float) $boutique->tva_taux / 100)), 0, ',', ' ') }} GNF</strong>.</span>
                            <span data-mode="ttc" @class(['d-none' => ! $boutique->prix_ttc])>Vos prix de vente sont <strong>TVA comprise</strong> : le client paie exactement le prix affiché, la TVA en est <strong>extraite</strong> pour les factures et la déclaration.
                                Exemple avec <span data-taux-exemple>{{ $tauxTexte }}</span> % : un article à 10 000 GNF est encaissé 10 000 GNF, dont <strong data-tva-extraite>{{ number_format((int) round(10000 * (float) $boutique->tva_taux / (100 + (float) $boutique->tva_taux)), 0, ',', ' ') }} GNF</strong> de TVA.</span>
                            Un produit peut avoir son propre taux ou être exonéré (fiche produit → TVA).
                        </div>
                    </div>
                </div>
            </div>
            <div class="bloc mt-4">
                <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-sliders me-1"></i>Règles de gestion</h2>
                    <span class="small text-doux">Laissez vide pour ne pas limiter</span></div>
                <div class="bloc-corps row g-3">
                    <div class="col-sm-6"><label class="form-label" for="objectif_mensuel">Objectif de ventes du mois (GNF)</label>
                        <input name="objectif_mensuel" id="objectif_mensuel" data-montant inputmode="numeric" value="{{ old('objectif_mensuel', $boutique->objectif_mensuel) }}" class="form-control text-end" placeholder="Ex. : 50 000 000">
                        <div class="form-text">Suivi sur le tableau de bord avec une projection de fin de mois. Vide = pas d'objectif.</div></div>
                    @if (fonction('relances'))
                    <div class="col-sm-6"><label class="form-label" for="cadeau_anniversaire">Cadeau d'anniversaire (facultatif)</label>
                        <input name="cadeau_anniversaire" id="cadeau_anniversaire" maxlength="200" value="{{ old('cadeau_anniversaire', $boutique->cadeau_anniversaire) }}" class="form-control"
                               placeholder="Ex. : -10 % sur votre achat cette semaine">
                        <div class="form-text">Ajouté au message d'anniversaire envoyé aux clients. Vide = simple vœu.</div></div>
                    @endif
                    <div class="col-sm-6"><label class="form-label" for="remise_max_pct">Remise maximale (%)</label>
                        <input type="number" step="0.5" min="0" max="100" name="remise_max_pct" id="remise_max_pct" value="{{ old('remise_max_pct', $boutique->remise_max_pct) }}" class="form-control">
                        <div class="form-text">Au-delà, la caisse refuse la vente.</div></div>
                    <div class="col-sm-6"><label class="form-label" for="delai_annulation_heures">Annulation d'une vente possible pendant (heures)</label>
                        <input type="number" min="1" max="720" name="delai_annulation_heures" id="delai_annulation_heures" value="{{ old('delai_annulation_heures', $boutique->delai_annulation_heures) }}" class="form-control">
                        <div class="form-text">Ensuite, seul l'administrateur peut annuler.</div></div>
                    <div class="col-sm-6"><label class="form-label" for="delai_retour_jours">Retour de marchandise accepté pendant (jours)</label>
                        <input type="number" min="1" max="365" name="delai_retour_jours" id="delai_retour_jours" value="{{ old('delai_retour_jours', $boutique->delai_retour_jours) }}" class="form-control">
                        <div class="form-text">Ensuite, seul l'administrateur peut accepter un retour.</div></div>
                    <div class="col-sm-6"><label class="form-label" for="validite_devis_jours">Validité des devis (jours)</label>
                        <input type="number" min="1" max="180" name="validite_devis_jours" id="validite_devis_jours" value="{{ old('validite_devis_jours', $boutique->validite_devis_jours ?? 15) }}" class="form-control">
                        <div class="form-text">Durée pendant laquelle les prix d'un devis sont garantis.</div></div>
                    <div class="col-sm-6"><label class="form-label" for="couverture_stock_jours">Stock à prévoir pour (jours)</label>
                        <input type="number" min="1" max="180" name="couverture_stock_jours" id="couverture_stock_jours" value="{{ old('couverture_stock_jours', $boutique->couverture_stock_jours ?? 14) }}" class="form-control">
                        <div class="form-text">Sert à calculer « À commander » : le délai entre deux commandes fournisseur.</div></div>
                    <div class="col-sm-6"><label class="form-label" for="alerte_peremption_jours">Alerter avant péremption (jours)</label>
                        <input type="number" min="1" max="365" name="alerte_peremption_jours" id="alerte_peremption_jours" value="{{ old('alerte_peremption_jours', $boutique->alerte_peremption_jours ?? 30) }}" class="form-control">
                        <div class="form-text">Les lots qui périment dans ce délai sont signalés pour être vendus en priorité.</div></div>
                    @if (fonction('fidelite'))
                    <div class="col-sm-6"><label class="form-label" for="fidelite_taux">Fidélité : points gagnés (% des achats payés)</label>
                        <input type="number" step="0.5" min="0" max="20" name="fidelite_taux" id="fidelite_taux" value="{{ old('fidelite_taux', $boutique->fidelite_taux ?? 0) }}" class="form-control">
                        <div class="form-text">1 point = 1 GNF, utilisable à la caisse. 0 = programme désactivé. Exemple : 2 % → 2 000 points pour 100 000 GNF payés.</div></div>
                    <div class="col-sm-6"><label class="form-label" for="fidelite_minimum">Fidélité : points minimum pour les utiliser</label>
                        <input name="fidelite_minimum" id="fidelite_minimum" data-montant inputmode="numeric" value="{{ old('fidelite_minimum', $boutique->fidelite_minimum) }}" class="form-control text-end">
                        <div class="form-text">Évite d'utiliser les points par petites sommes.</div></div>
                    @endif
                    <div class="col-sm-6"><label class="form-label" for="periode_verrouillee_jusquau">Période clôturée jusqu'au</label>
                        <input type="date" name="periode_verrouillee_jusquau" id="periode_verrouillee_jusquau" max="{{ now()->subDay()->toDateString() }}"
                               value="{{ old('periode_verrouillee_jusquau', $boutique->periode_verrouillee_jusquau?->toDateString()) }}" class="form-control"
                               @disabled(! auth()->user()->role?->systeme)>
                        <div class="form-text">Mois arrêté (déclaration faite, inventaire fait) : plus aucune annulation, retour, dépense ou réception à ces dates.
                            @unless (auth()->user()->role?->systeme) Réservé à l'administrateur.@endunless</div></div>
                    <div class="col-sm-6"><label class="form-label" for="plafond_credit_defaut">Plafond de crédit par client (GNF)</label>
                        <input name="plafond_credit_defaut" id="plafond_credit_defaut" data-montant inputmode="numeric" value="{{ old('plafond_credit_defaut', $boutique->plafond_credit_defaut) }}" class="form-control text-end">
                        <div class="form-text">Dette maximale d'un client (modifiable client par client).</div></div>
                    <div class="col-sm-6"><label class="form-label" for="delai_credit_jours">Délai de remboursement d'un crédit (jours)</label>
                        <input type="number" min="1" max="365" name="delai_credit_jours" id="delai_credit_jours" value="{{ old('delai_credit_jours', $boutique->delai_credit_jours) }}" class="form-control">
                        <div class="form-text">Un client en retard ne peut plus prendre à crédit.</div></div>
                    <div class="col-sm-6"><label class="form-label" for="methode_cout">Calcul du prix d'achat à la réception</label>
                        <select name="methode_cout" id="methode_cout" class="form-select">
                            <option value="dernier" @selected(old('methode_cout', $boutique->methode_cout) === 'dernier')>Dernier prix payé</option>
                            <option value="cmp" @selected(old('methode_cout', $boutique->methode_cout) === 'cmp')>Coût moyen pondéré (plus juste pour les marges)</option>
                        </select></div>
                    <div class="col-sm-6 d-flex align-items-end"><div class="form-check form-switch mb-2">
                        <input type="checkbox" name="vente_a_perte" value="1" id="vente_a_perte" class="form-check-input" @checked(old('vente_a_perte', $boutique->vente_a_perte))>
                        <label for="vente_a_perte" class="form-check-label">Autoriser la vente sous le prix d'achat</label></div></div>
                    <div class="col-12"><div class="form-check form-switch">
                        <input type="hidden" name="resume_quotidien" value="0">
                        <input type="checkbox" name="resume_quotidien" value="1" id="resume_quotidien" class="form-check-input" @checked(old('resume_quotidien', $boutique->resume_quotidien ?? true))>
                        <label for="resume_quotidien" class="form-check-label">Recevoir chaque matin le résumé de la veille (ventes, caisses, stock bas, crédits en retard)</label></div></div>
                </div>
            </div>
            @if (fonction('vitrine'))
                @php
                    $lienVitrine = route('vitrine.index', $boutique->slug);
                    // Partage libre (le commerçant choisit ses contacts ou son statut WhatsApp)
                    $partage = 'https://wa.me/?text='.rawurlencode("Découvrez notre catalogue et commandez en ligne : {$lienVitrine}");
                @endphp
                <div class="bloc mt-4" id="vitrine">
                    <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-shop-window me-1"></i>Vitrine en ligne</h2></div>
                    <div class="bloc-corps row g-3">
                        <div class="col-12 small text-doux">Une page publique avec vos produits, vos prix et leur disponibilité. Vos clients composent leur panier
                            depuis leur téléphone ; la commande arrive dans <strong>Devis et proformas</strong> (badge « Vitrine ») et vous est envoyée par WhatsApp.
                            Rien ne sort du stock tant que vous ne l'avez pas transformée en vente.</div>
                        <div class="col-12"><div class="form-check form-switch">
                            <input type="hidden" name="vitrine_active" value="0">
                            <input type="checkbox" name="vitrine_active" value="1" id="vitrine_active" class="form-check-input" @checked(old('vitrine_active', $boutique->vitrine_active))>
                            <label for="vitrine_active" class="form-check-label">Publier ma vitrine en ligne</label></div></div>
                        <div class="col-12"><div class="form-check form-switch">
                            <input type="hidden" name="vitrine_stock_visible" value="0">
                            <input type="checkbox" name="vitrine_stock_visible" value="1" id="vitrine_stock_visible" class="form-check-input" @checked(old('vitrine_stock_visible', $boutique->vitrine_stock_visible))>
                            <label for="vitrine_stock_visible" class="form-check-label">Afficher les quantités en stock (sinon seulement « Disponible » / « En rupture »)</label></div></div>
                        <div class="col-12"><label class="form-label" for="vitrine_message">Message d'accueil</label>
                            <input name="vitrine_message" id="vitrine_message" maxlength="300" class="form-control" value="{{ old('vitrine_message', $boutique->vitrine_message) }}"
                                placeholder="Ex. : Livraison gratuite à Kaloum dès 200 000 GNF">
                            <div class="form-text">Les produits se retirent un par un de la vitrine depuis leur fiche.@if (! $boutique->telephone) <span class="text-danger">Renseignez le téléphone de la boutique pour recevoir les commandes sur WhatsApp.</span>@endif</div></div>
                        @if ($boutique->vitrine_active)
                            <div class="col-12">
                                <label class="form-label" for="lienVitrine">Adresse de votre vitrine</label>
                                <div class="input-group">
                                    <input id="lienVitrine" class="form-control" value="{{ $lienVitrine }}" readonly>
                                    <a href="{{ $lienVitrine }}" target="_blank" rel="noopener" class="btn btn-outline-secondary" aria-label="Ouvrir la vitrine"><i class="bi bi-box-arrow-up-right"></i></a>
                                    @if ($partage)<a href="{{ $partage }}" target="_blank" rel="noopener" class="btn btn-success"><i class="bi bi-whatsapp"></i><span class="d-none d-sm-inline ms-1">Partager</span></a>@endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
            <div class="mt-4"><button class="btn btn-primary btn-lg">Enregistrer les paramètres</button></div>
        </div>
    </form>
@endsection
@push('scripts')
<script>
    // Exemple de TVA recalculé quand on change le taux
    (() => {
        const taux = document.getElementById('tva_taux');
        if (!taux) return;
        const maj = () => {
            const t = parseFloat(String(taux.value).replace(',', '.')) || 0;
            document.querySelectorAll('[data-taux-exemple]').forEach(e => e.textContent = String(t).replace('.', ','));
            document.querySelector('[data-ttc-exemple]').textContent = Math.round(10000 * (1 + t / 100)).toLocaleString('fr-FR') + ' GNF';
            document.querySelector('[data-tva-extraite]').textContent = Math.round(10000 * t / (100 + t)).toLocaleString('fr-FR') + ' GNF';
            const ttc = document.getElementById('prix_ttc').checked;
            document.querySelector('[data-mode=ht]').classList.toggle('d-none', ttc);
            document.querySelector('[data-mode=ttc]').classList.toggle('d-none', !ttc);
            // Changement de mode (TVA active) : proposer l'ajustement des prix
            const bloc = document.getElementById('blocAjusterPrix');
            const change = document.getElementById('tva_active').checked && (ttc ? 1 : 0) !== Number(bloc.dataset.modeInitial);
            bloc.classList.toggle('d-none', !change);
            document.getElementById('ajuster_prix').disabled = !change;
            bloc.querySelector('[data-sens=ttc]').classList.toggle('d-none', !ttc);
            bloc.querySelector('[data-sens=ht]').classList.toggle('d-none', ttc);
        };
        document.getElementById('tva_active').addEventListener('change', maj);
        maj();
        taux.addEventListener('input', maj);
        document.getElementById('prix_ttc').addEventListener('change', maj);
    })();
</script>
<script>
    // Ajout / retrait des couleurs 2 et 3, aperçu de la charte
    (() => {
        const nuances = [...document.querySelectorAll('[data-nuance]')];
        const apercu = document.getElementById('apercuCharte');
        const ajouter = document.getElementById('ajouterCouleur');
        const maj = () => {
            const actives = nuances.filter(n => !n.classList.contains('d-none'));
            apercu.innerHTML = actives.map(n => `<span style="background:${n.querySelector('input').value}"></span>`).join('');
            ajouter.disabled = actives.length >= 3;
        };
        ajouter.addEventListener('click', () => {
            const cachee = nuances.find(n => n.classList.contains('d-none'));
            if (!cachee) return;
            cachee.classList.remove('d-none');
            cachee.querySelector('input').disabled = false;
            cachee.querySelector('input').click();
            maj();
        });
        nuances.forEach(n => {
            n.querySelector('input').addEventListener('input', maj);
            n.querySelector('[data-retirer]')?.addEventListener('click', () => {
                n.classList.add('d-none');
                n.querySelector('input').disabled = true;
                maj();
            });
        });
        maj();
    })();
</script>
@endpush
