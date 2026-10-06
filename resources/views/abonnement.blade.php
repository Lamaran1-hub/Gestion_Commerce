@extends('layouts.app')
@section('titre', 'Ma licence')
@section('contenu')
    @php($ed = \App\Support\Plateforme::tout())
    <div class="entete-page"><h1>Ma licence</h1>
        <a href="{{ route('assistance.create', ['categorie' => 'licence']) }}" class="btn btn-outline-primary"><i class="bi bi-chat-dots me-1"></i>Contacter l'éditeur</a></div>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="bloc bloc-corps text-center py-4">
                @if ($b->licencePayee())
                    <i class="bi bi-patch-check fs-1 text-success"></i>
                    <h2 class="h4 mt-2 mb-1">Votre licence est active</h2>
                @elseif ($b->estActive())
                    <i class="bi bi-hourglass-split fs-1 text-warning"></i>
                    <h2 class="h4 mt-2 mb-1">Période d'essai</h2>
                    <p class="text-doux mb-1">Votre logo personnalisé s'affichera dès l'activation de votre licence.</p>
                @else
                    <i class="bi bi-lock fs-1 text-danger"></i>
                    <h2 class="h4 mt-2 mb-1">{{ $b->statut === 'suspendu' ? 'Votre boutique est suspendue' : 'Votre licence a expiré' }}</h2>
                    <p class="text-doux mb-1">Vos données sont conservées. L'accès sera rétabli dès la réception du paiement.</p>
                @endif
                <p class="mb-0">Formule <strong>{{ $b->plan?->nom ?? 'standard' }}</strong>
                    @if ($b->abonnement_expire_le) · échéance le <strong>{{ $b->abonnement_expire_le->format('d/m/Y') }}</strong>@else · sans échéance @endif</p>
                @if ($b->estActive())<a href="{{ route('dashboard') }}" class="btn btn-primary mt-3">Retour à la boutique</a>@endif
            </div>
            @php($enCours = $commandes->firstWhere('statut', 'en_attente'))
            @if ($enCours && $enCours->created_at->gt(now()->subHours(\App\Services\Paiement\LicenceEnLigneService::EXPIRATION_HEURES)))
                <div class="alert alert-info mt-3 d-flex gap-2 align-items-center" id="suiviPaiement" data-url="{{ route('licence.statut', $enCours->reference) }}">
                    <span class="spinner-border spinner-border-sm"></span>
                    <div class="flex-grow-1">Paiement {{ $enCours->reference }} en cours de confirmation ({{ gnf($enCours->montant) }}).
                        <span class="d-block small">Validez l'opération sur votre téléphone si votre opérateur vous le demande. Cette page se met à jour toute seule.</span></div>
                    @if ($enCours->url_paiement)<a href="{{ $enCours->url_paiement }}" class="btn btn-sm btn-light">Reprendre le paiement</a>@endif
                </div>
            @endif

            @if ($enLigne && $plans->isNotEmpty())
                {{-- Achat en ligne : le montant est recalculé par le serveur ; l'affichage n'est qu'indicatif --}}
                <form method="post" action="{{ route('licence.commander') }}" class="bloc mt-3" id="formAchat"
                      data-confirmer="Vous allez être redirigé vers la page de paiement sécurisée Djomy." data-confirmer-titre="Payer ma licence" data-confirmer-bouton="Continuer vers le paiement">
                    @csrf
                    @if (config('services.djomy.mode') === 'sandbox')
                        <div class="alert alert-warning rounded-0 rounded-top mb-0 small"><i class="bi bi-cone-striped me-1"></i>
                            <strong>MODE TEST</strong> : aucun argent réel n'est prélevé. Utilisez les numéros de test fournis par Djomy.</div>
                    @endif
                    <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-phone me-1"></i>Payer ma licence en ligne</h2>
                        <span class="d-flex gap-1">@foreach (\App\Support\MoyensDjomy::actifs() as $code)@include('partials.badge-moyen', ['code' => $code])@endforeach</span></div>
                    <div class="bloc-corps row g-3">
                        <div class="col-12"><label class="form-label" for="plan_id">Formule</label>
                            <select name="plan_id" id="plan_id" class="form-select">
                                @foreach ($plans as $p)
                                    @php($trop = $b->depassementsFormule($p))
                                    <option value="{{ $p->id }}" data-prix="{{ $p->prix_mensuel }}" @selected(old('plan_id', $b->plan_id) == $p->id) @disabled($trop)>
                                        {{ $p->nom }} — {{ gnf($p->prix_mensuel) }}/mois{{ $trop ? ' (trop petite : '.implode(', ', $trop).')' : '' }}</option>
                                @endforeach
                            </select></div>
                        <div class="col-6"><label class="form-label" for="mois">Durée</label>
                            <select name="mois" id="mois" class="form-select">
                                @foreach (\App\Services\Paiement\LicenceEnLigneService::DUREES as $m)<option value="{{ $m }}" @selected((int) old('mois', 12) === $m)>{{ $m }} mois</option>@endforeach
                            </select></div>
                        <div class="col-6"><label class="form-label" for="numero_payeur">Téléphone du payeur</label>
                            <div class="input-group"><span class="input-group-text">+224</span>
                                <input name="numero_payeur" id="numero_payeur" value="{{ old('numero_payeur', numero_local(auth()->user()->telephone)) }}" class="form-control" inputmode="tel" placeholder="6XX XX XX XX" required></div></div>
                        <div class="col-12">
                            <span class="form-label d-block">Moyen de paiement</span>
                            <div class="moyens-paiement">
                                <label class="moyen"><input type="radio" name="moyen" value="" @checked(old('moyen', '') === '')>
                                    <span><i class="bi bi-grid-3x3-gap"></i><strong>Au choix</strong><small>sur la page Djomy</small></span></label>
                                @foreach (\App\Support\MoyensDjomy::actifs() as $code)
                                    <label class="moyen"><input type="radio" name="moyen" value="{{ $code }}" @checked(old('moyen') === $code)>
                                        <span>@include('partials.badge-moyen', ['code' => $code])<strong>{{ \App\Support\MoyensDjomy::libelle($code) }}</strong>
                                            <small>{{ \App\Support\MoyensDjomy::description($code) }}</small></span></label>
                                @endforeach
                            </div>
                            <div class="form-text">Mobile money et portefeuilles : validez le paiement sur le téléphone indiqué. Carte : saisie sur la page sécurisée Djomy.</div>
                        </div>
                        <div class="col-12 d-flex justify-content-between align-items-center bg-light rounded p-2">
                            <span class="text-doux">Total à payer</span><strong class="fs-5 montant" id="totalAchat">—</strong></div>
                        <div class="col-12 small text-doux" id="echeanceAchat"></div>
                        <div class="col-12 d-grid"><button class="btn btn-primary btn-lg"><i class="bi bi-lock me-1"></i>Payer maintenant</button></div>
                        <div class="col-12 small text-doux"><i class="bi bi-shield-check me-1"></i>Paiement traité par Djomy. Votre licence est activée automatiquement
                            dès la confirmation, et le reçu apparaît dans « Mes paiements ».</div>
                    </div>
                </form>
            @endif

            <div class="bloc bloc-corps mt-3">
                <h2 class="h6"><i class="bi bi-wallet2 me-1"></i>{{ $enLigne ? 'Autres moyens de paiement' : 'Renouveler ou activer ma licence' }}</h2>
                @if (! empty($ed['infos_paiement']))<p style="white-space:pre-line" class="mb-2">{{ $ed['infos_paiement'] }}</p>@endif
                <div class="small vstack gap-1">
                    <div class="fw-semibold">{{ $ed['societe'] ?? config('app.name') }}</div>
                    @if (! empty($ed['telephone']))<div><i class="bi bi-telephone me-1"></i><a href="tel:{{ $ed['telephone'] }}">{{ $ed['telephone'] }}</a></div>@endif
                    @if (! empty($ed['whatsapp']))<div><i class="bi bi-whatsapp me-1"></i>{{ $ed['whatsapp'] }}</div>@endif
                    @if (! empty($ed['email']))<div><i class="bi bi-envelope me-1"></i>{{ $ed['email'] }}</div>@endif
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Mes paiements</h2></div>
                <table class="table"><thead><tr><th>N°</th><th>Date</th><th>Période</th><th class="text-end">Montant</th></tr></thead><tbody>
                    @forelse ($paiements as $p)
                        <tr><td>{{ $p->numero }}</td><td>{{ $p->paye_le->format('d/m/Y') }}</td><td class="small">{{ $p->libellePeriode() }}</td>
                            <td class="text-end montant">{{ gnf($p->montant) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="vide">Aucun paiement enregistré.</td></tr>
                    @endforelse
                </tbody></table>
            </div>
            @if ($commandes->isNotEmpty())
                <div class="bloc mt-3">
                    <div class="bloc-entete"><h2 class="mb-0">Paiements en ligne</h2></div>
                    <table class="table"><tbody>
                        @foreach ($commandes as $c)
                            <tr class="{{ $suivie === $c->reference ? 'table-active' : '' }}"><td class="small">{{ $c->reference }}<div class="text-doux">{{ $c->created_at->format('d/m/Y H:i') }}</div></td>
                                <td class="small">{{ $c->plan?->nom }} · {{ $c->mois }} mois</td>
                                <td><span class="etat {{ $c->classeStatut() }}">{{ $c->libelleStatut() }}</span></td>
                                <td class="text-end montant">{{ gnf($c->montant) }}</td></tr>
                        @endforeach
                    </tbody></table>
                </div>
            @endif
        </div>
    </div>
    @include('partials.ma-formule')
@endsection
@push('scripts')
<script>
    // Total indicatif et nouvelle échéance (le serveur recalcule le montant)
    (() => {
        const f = document.getElementById('formAchat');
        if (!f) return;
        const plan = f.querySelector('#plan_id'), mois = f.querySelector('#mois');
        const echeance = @json($b->abonnement_expire_le && $b->abonnement_expire_le->isFuture() ? $b->abonnement_expire_le->toDateString() : null);
        const maj = () => {
            const prix = Number(plan.selectedOptions[0]?.dataset.prix || 0), m = Number(mois.value);
            document.getElementById('totalAchat').textContent = gnf(prix * m);
            const debut = echeance ? new Date(echeance + 'T00:00:00') : new Date();
            if (echeance) debut.setDate(debut.getDate() + 1);
            const fin = new Date(debut); fin.setMonth(fin.getMonth() + m); fin.setDate(fin.getDate() - 1);
            document.getElementById('echeanceAchat').textContent = 'Licence valable jusqu\'au ' + fin.toLocaleDateString('fr-FR') + (echeance ? ' (à la suite de la licence en cours).' : '.');
        };
        plan.addEventListener('change', maj); mois.addEventListener('change', maj); maj();
    })();

    // Suivi du paiement en cours : vérification toutes les 5 s pendant 5 minutes, puis rechargement
    (() => {
        const bloc = document.getElementById('suiviPaiement');
        if (!bloc) return;
        let essais = 0;
        const verifier = async () => {
            try {
                const r = await fetch(bloc.dataset.url, { headers: { Accept: 'application/json' } });
                const d = await r.json();
                if (d.termine) { window.location.reload(); return; }
            } catch { /* réseau : on réessaie */ }
            if (++essais < 60) setTimeout(verifier, 5000);
        };
        setTimeout(verifier, 3000);
    })();
</script>
@endpush
