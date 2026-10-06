@extends('layouts.app')
@section('titre', 'Paiements en ligne')
@section('contenu')
    <div class="entete-page"><div><h1>Paiements en ligne (Djomy)
            @if (config('services.djomy.mode') === 'sandbox')<span class="etat etat-alerte align-middle">MODE TEST</span>@else<span class="etat etat-ok align-middle">PRODUCTION</span>@endif</h1>
        <div class="text-doux">Achats de licence effectués par vos clients. Chaque paiement est vérifié auprès de Djomy avant activation.
            Fonds reversés sur le {{ numero_affiche(config('services.djomy.numero_marchand')) }}.</div></div>
        <form method="post" action="{{ route('admin.commandes.diagnostic') }}">@csrf
            <button class="btn btn-outline-primary"><i class="bi bi-activity me-1"></i>Tester la connexion Djomy</button></form></div>

    @if (session('diagnostic_djomy'))
        <div class="bloc mb-3"><div class="bloc-entete"><h2 class="mb-0">Diagnostic Djomy</h2></div>
            <ul class="list-unstyled bloc-corps mb-0 vstack gap-2">
                @foreach (session('diagnostic_djomy') as [$libelle, $ok, $detail])
                    <li class="d-flex gap-2"><i class="bi bi-{{ $ok === true ? 'check-circle-fill text-success' : ($ok === false ? 'x-circle-fill text-danger' : 'exclamation-circle-fill text-warning') }}"></i>
                        <div><strong>{{ $libelle }}</strong><div class="small text-doux">{{ $detail }}</div></div></li>
                @endforeach
            </ul></div>
    @endif

    @unless ($disponible)
        <div class="alert alert-warning"><i class="bi bi-plug me-1"></i><strong>Paiement en ligne désactivé.</strong>
            Renseignez <code>DJOMY_CLIENT_ID</code>, <code>DJOMY_CLIENT_SECRET</code> et <code>DJOMY_ACTIF=true</code> dans le fichier <code>.env</code>,
            puis déclarez l'URL <code>{{ route('webhooks.djomy') }}</code> comme webhook dans votre espace développeur Djomy.</div>
    @endunless
    @if ($aTraiter)
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>{{ $aTraiter }} paiement(s) demandent votre attention (à valider ou en anomalie).</div>
    @endif

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('admin.commandes.index') }}" class="btn btn-sm {{ request('statut') ? 'btn-outline-primary' : 'btn-primary' }}">Toutes</a>
        @foreach (\App\Models\CommandeLicence::STATUTS as $k => [$l])
            <a href="{{ route('admin.commandes.index', ['statut' => $k]) }}" class="btn btn-sm {{ request('statut') === $k ? 'btn-primary' : 'btn-outline-primary' }}">{{ $l }}</a>
        @endforeach
    </div>

    <div class="bloc"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Référence</th><th>Client</th><th>Formule</th><th class="text-end">Montant</th><th>État</th><th>Djomy</th><th></th></tr></thead>
        <tbody>
        @forelse ($commandes as $c)
            <tr>
                <td class="fw-semibold">{{ $c->reference }} @if ($c->estTest())<span class="etat etat-neutre">TEST</span>@endif
                    <div class="small text-doux">{{ $c->created_at->format('d/m/Y H:i') }}</div></td>
                <td>@if ($c->boutique)<a href="{{ route('admin.boutiques.show', $c->boutique) }}">{{ $c->boutique->nom }}</a>@endif
                    <div class="small text-doux">{{ $c->acheteur?->nomComplet() }} · {{ $c->numero_payeur }}</div></td>
                <td class="small">{{ $c->plan?->nom }} · {{ $c->mois }} mois<div class="text-doux d-flex align-items-center gap-1 mt-1">@if ($code = $c->moyen_utilise ?? $c->moyen)@include('partials.badge-moyen', ['code' => \App\Support\MoyensDjomy::normaliser($code)])@endif{{ $c->libelleMoyen() }}</div></td>
                <td class="text-end montant">{{ gnf($c->montant) }}
                    @if ($c->montant_recu !== null && $c->montant_recu !== $c->montant)<div class="small text-danger">reçu : {{ gnf($c->montant_recu) }}</div>@endif</td>
                <td><span class="etat {{ $c->classeStatut() }}">{{ $c->libelleStatut() }}</span>
                    @if ($c->paiementLicence)<div class="small"><a href="{{ route('admin.paiements.recu', $c->paiementLicence) }}" target="_blank">{{ $c->paiementLicence->numero }}</a></div>@endif</td>
                <td class="small text-doux">{{ $c->statut_fournisseur ?? '—' }}<div>{{ \Illuminate\Support\Str::limit($c->transaction_id, 18) }}</div>
                    @if ($c->verifiee_le)<div>vérifié {{ $c->verifiee_le->format('d/m H:i') }}</div>@endif</td>
                <td class="text-end text-nowrap">
                    @if (! $c->estFinale() && $c->transaction_id)
                        <form method="post" action="{{ route('admin.commandes.verifier', $c) }}" class="d-inline">@csrf
                            <button class="btn btn-sm btn-light" title="Vérifier maintenant chez Djomy"><i class="bi bi-arrow-repeat"></i></button></form>
                    @endif
                    @if (in_array($c->statut, ['a_valider', 'anomalie'], true))
                        <form method="post" action="{{ route('admin.commandes.activer', $c) }}" class="d-inline"
                              data-confirmer="{{ $c->statut === 'anomalie' ? 'Montant reçu inférieur au montant dû. Activer quand même la licence de '.$c->boutique?->nom.' ?' : 'Activer la licence de '.$c->boutique?->nom.' ?' }}"
                              data-confirmer-titre="Activer la licence" data-confirmer-bouton="Oui, activer" data-confirmer-type="{{ $c->statut === 'anomalie' ? 'alerte' : 'primaire' }}">@csrf
                            <button class="btn btn-sm btn-success"><i class="bi bi-patch-check me-1"></i>Activer</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="vide"><i class="bi bi-phone"></i>Aucun paiement en ligne pour l'instant.</td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    <div class="mt-3">{{ $commandes->links() }}</div>
@endsection
