@extends('layouts.app')
@section('titre', 'Tableau de bord')
@section('contenu')
    @include('partials.demarrage')
    @if (fonction('relances') && auth()->user()->aPermission('clients.voir'))
        @php
            $anniversaires = \App\Models\Client::whereIn('id', \App\Http\Controllers\ClientController::idsAnniversaires(1))->get()->reject->voeuEnvoyeCetteAnnee();
        @endphp
        @if ($anniversaires->isNotEmpty())
            <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="status">
                <span class="fs-5">🎂</span>
                <div class="flex-grow-1">Anniversaire aujourd'hui : <strong>{{ $anniversaires->take(3)->map->nomComplet()->implode(', ') }}</strong>{{ $anniversaires->count() > 3 ? ' et '.($anniversaires->count() - 3).' autre(s)' : '' }}.
                    Un petit message fait toujours plaisir.</div>
                <a href="{{ route('clients.index', ['segment' => 'anniversaires']) }}" class="btn btn-sm btn-warning">Envoyer les vœux</a>
            </div>
        @endif
    @endif
    @can('ventes.voir')
        @php
            $commandesEnLigne = \App\Models\Devis::commandesATraiter()->latest('id')->get(['id', 'numero', 'client_nom', 'client_telephone', 'total_ttc', 'created_at']);
        @endphp
        @if ($commandesEnLigne->isNotEmpty())
            <div class="alert alert-success d-flex flex-wrap align-items-center gap-2" role="status">
                <i class="bi bi-bag-check fs-5"></i>
                <div class="flex-grow-1"><strong>{{ $commandesEnLigne->count() }} commande(s) en ligne à confirmer</strong>
                    ({{ gnf($commandesEnLigne->sum('total_ttc')) }}) — la plus ancienne date {{ $commandesEnLigne->last()->created_at->diffForHumans() }}.
                    Rappelez vite vos clients : une commande confirmée rapidement est une vente gagnée.</div>
                <a href="{{ route('devis.index', ['etat' => 'en_ligne']) }}" class="btn btn-sm btn-success">Traiter les commandes</a>
            </div>
        @endif
        @php
            // Livraisons prévues aujourd'hui ou en retard
            $livraisonsDues = \App\Models\Vente::aLivrer()->whereNotNull('livraison_prevue_le')->whereDate('livraison_prevue_le', '<=', now()->toDateString())->get(['id', 'livraison_prevue_le']);
            $livraisonsRetard = $livraisonsDues->filter(fn ($v) => $v->livraison_prevue_le->lt(today()))->count();
        @endphp
        @if ($livraisonsDues->isNotEmpty())
            <div class="alert {{ $livraisonsRetard ? 'alert-danger' : 'alert-info' }} d-flex flex-wrap align-items-center gap-2" role="status">
                <i class="bi bi-truck fs-5"></i>
                <div class="flex-grow-1"><strong>{{ $livraisonsDues->count() }} livraison(s) à faire aujourd'hui</strong>{{ $livraisonsRetard ? ', dont '.$livraisonsRetard.' en retard' : '' }}.</div>
                <a href="{{ route('livraisons.index') }}" class="btn btn-sm {{ $livraisonsRetard ? 'btn-danger' : 'btn-primary' }}">Voir les livraisons</a>
            </div>
        @endif
        @php
            // Acomptes encaissés sur des devis expirés : l'argent du client est bloqué, il faut recréer le devis ou rembourser
            $acomptesBloques = \App\Models\Devis::where('statut', 'en_cours')->where('acompte', '>', 0)->whereDate('valable_jusqu_au', '<', now()->toDateString())->get(['id', 'acompte']);
        @endphp
        @if ($acomptesBloques->isNotEmpty())
            <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="status">
                <i class="bi bi-wallet2 fs-5"></i>
                <div class="flex-grow-1"><strong>{{ gnf($acomptesBloques->sum('acompte')) }} d'acomptes clients</strong> sur {{ $acomptesBloques->count() }} devis expiré(s) :
                    recréez le devis au prix du jour (l'acompte suit) ou remboursez le client.</div>
                <a href="{{ route('devis.index', ['etat' => 'expire']) }}" class="btn btn-sm btn-warning">Voir les devis</a>
            </div>
        @endif
    @endcan
    @can('approvisionnements.gerer')
        @php($commandesRetard = \App\Models\CommandeFournisseur::enAttente()->whereNotNull('livraison_prevue_le')->whereDate('livraison_prevue_le', '<', now()->toDateString())->count())
        @if ($commandesRetard)
            <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status">
                <i class="bi bi-clipboard-x fs-5"></i>
                <div class="flex-grow-1"><strong>{{ $commandesRetard }} commande(s) fournisseur</strong> pas encore livrée(s) à la date prévue : relancez le fournisseur.</div>
                <a href="{{ route('commandes-fournisseur.index') }}" class="btn btn-sm btn-primary">Voir les commandes</a>
            </div>
        @endif
    @endcan
    <div class="entete-page">
        <h1>Tableau de bord</h1>
        <div class="btn-group" role="group" aria-label="Période">
            @foreach ($periodes as $cle => $libelle)
                <a href="{{ route('dashboard', ['periode' => $cle]) }}" class="btn btn-sm {{ $periode === $cle ? 'btn-primary' : 'btn-outline-primary' }}">{{ $libelle }}</a>
            @endforeach
        </div>
    </div>

    @php($voirMarge = auth()->user()->aPermission('produits.prix_achat'))
    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="kpi-principal d-flex flex-column justify-content-between">
                <div>
                    <div class="etiquette">Chiffre d'affaires · {{ strtolower($periodes[$periode]) }}</div>
                    <div class="valeur montant">{{ gnf($i['ca']) }}</div>
                </div>
                <div class="d-flex flex-wrap gap-4 mt-3">
                    <div><div class="etiquette small">Ventes</div><div class="fw-bold fs-5">{{ $i['nb_ventes'] }}</div></div>
                    <div><div class="etiquette small">Panier moyen</div><div class="fw-bold fs-5 montant">{{ gnf($i['panier_moyen']) }}</div></div>
                    <div><div class="etiquette small">Encaissé</div><div class="fw-bold fs-5 montant">{{ gnf($i['encaisse']) }}</div></div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="bloc h-100">
                <div class="row g-0 h-100">
                    <div class="col-sm-6 border-end">
                        @if ($voirMarge)
                            <div class="kpi"><div class="etiquette">Marge brute</div><div class="valeur montant">{{ gnf($i['marge']) }}</div></div>
                        @endif
                        <div class="kpi"><div class="etiquette">Dépenses</div><div class="valeur montant">{{ gnf($i['depenses']) }}</div></div>
                        @if ($voirMarge)
                            <div class="kpi"><div class="etiquette">Bénéfice (marge − dépenses)</div>
                                <div class="valeur montant {{ $i['benefice'] < 0 ? 'text-danger' : '' }}">{{ gnf($i['benefice']) }}</div></div>
                        @endif
                    </div>
                    <div class="col-sm-6">
                        <div class="kpi"><div class="etiquette">Crédits clients à encaisser</div>
                            <div class="valeur montant"><a href="{{ route('credits.index') }}" class="text-reset">{{ gnf($i['credits']) }}</a></div></div>
                        @if ($voirMarge)
                            <div class="kpi"><div class="etiquette">Valeur du stock (prix d'achat)</div><div class="valeur montant">{{ gnf($i['valeur_stock']) }}</div></div>
                        @endif
                        <div class="kpi"><div class="etiquette">Clients enregistrés</div><div class="valeur">{{ $i['nb_clients'] }}</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('partials.objectifs')

    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <div class="bloc h-100">
                <div class="bloc-entete"><h2 class="mb-0">Ventes des 12 derniers mois</h2></div>
                <div class="bloc-corps"><canvas id="graphVentes" height="110" aria-label="Graphique des ventes mensuelles"></canvas></div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="bloc h-100">
                <div class="bloc-entete"><h2 class="mb-0">Encaissements par mode</h2></div>
                <div class="bloc-corps">
                    @forelse ($parMode as $mode => $total)
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>{{ libelle_mode($mode) }}</span><span class="fw-semibold montant">{{ gnf($total) }}</span>
                        </div>
                    @empty
                        <p class="text-doux mb-0">Aucun encaissement sur la période.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-4">
            <div class="bloc h-100">
                <div class="bloc-entete"><h2 class="mb-0">Meilleures ventes</h2></div>
                <table class="table">
                    <tbody>
                    @forelse ($topProduits as $p)
                        <tr><td>{{ $p->designation }}<div class="small text-doux">{{ qte($p->quantite) }} vendu(s)</div></td>
                            <td class="text-end montant fw-semibold">{{ gnf($p->total) }}</td></tr>
                    @empty
                        <tr><td class="vide">Aucune vente sur la période.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="bloc h-100">
                <div class="bloc-entete">
                    <h2 class="mb-0">Stock à surveiller</h2>
                    @if ($nbAlertes)<a href="{{ route('produits.index', ['etat' => 'alerte']) }}" class="small">Voir les {{ $nbAlertes }}</a>
                        @can('approvisionnements.gerer')<a href="{{ route('stock.a-commander') }}" class="small ms-2"><i class="bi bi-cart-check"></i> Quoi commander ?</a>@endcan
                    @endif
                </div>
                <table class="table">
                    <tbody>
                    @forelse ($alertes as $p)
                        <tr><td><a href="{{ route('produits.show', $p) }}" class="text-reset">{{ $p->designation }}</a></td>
                            <td class="text-end"><span class="etat etat-{{ $p->etatStock() }}">{{ qte($p->stock) }} {{ $p->unite }}</span></td></tr>
                    @empty
                        <tr><td class="vide"><i class="bi bi-check2-circle"></i>Tous les produits sont au-dessus du seuil.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="bloc h-100">
                <div class="bloc-entete"><h2 class="mb-0">Dernières ventes</h2><a href="{{ route('ventes.index') }}" class="small">Tout voir</a></div>
                <table class="table table-hover">
                    <tbody>
                    @forelse ($dernieresVentes as $v)
                        <tr><td><a href="{{ route('ventes.show', $v) }}" class="text-reset fw-semibold">{{ $v->numero }}</a>
                                <div class="small text-doux">{{ $v->client?->nomComplet() ?? 'Client comptoir' }} · {{ $v->date_vente->format('d/m H:i') }}</div></td>
                            <td class="text-end montant">{{ gnf($v->total_ttc) }}<div>@include('partials.etat-vente', ['vente' => $v])</div></td></tr>
                    @empty
                        <tr><td class="vide">Aucune vente pour l'instant. <a href="{{ route('ventes.create') }}">Enregistrer la première</a></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
@push('scripts')
<script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
<script>
    const donnees = @json($graphique);
    const marque = getComputedStyle(document.documentElement).getPropertyValue('--marque').trim() || '#1F6F54';
    new Chart(document.getElementById('graphVentes'), {
        type: 'bar',
        data: { labels: donnees.map(d => d.label), datasets: [{ label: "Chiffre d'affaires", data: donnees.map(d => d.total), backgroundColor: marque, borderRadius: 6 }] },
        options: {
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => gnf(c.raw) } } },
            scales: { y: { ticks: { callback: v => v >= 1e6 ? (v / 1e6).toLocaleString('fr-FR') + ' M' : v.toLocaleString('fr-FR') } }, x: { grid: { display: false } } }
        }
    });
</script>
@endpush
