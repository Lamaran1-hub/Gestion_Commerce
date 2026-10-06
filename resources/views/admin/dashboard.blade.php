@extends('layouts.app')
@section('titre', 'Espace propriétaire')
@section('contenu')
    <div class="entete-page"><div><h1>Bonjour {{ auth()->user()->prenom }}</h1><div class="text-doux">Vue d'ensemble de vos clients et de vos licences.</div></div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.annonces.create') }}" class="btn btn-outline-primary"><i class="bi bi-megaphone me-1"></i>Annoncer une nouveauté</a>
            <a href="{{ route('admin.boutiques.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouveau client</a></div></div>

    @if ($motDePasseParDefaut)
        <div class="alert alert-danger d-flex gap-2 align-items-center"><i class="bi bi-shield-exclamation fs-4"></i>
            <div><strong>Sécurité :</strong> votre compte utilise encore le mot de passe par défaut du fichier <code>.env</code>.
                <a href="{{ route('profil.edit') }}" class="alert-link">Choisissez votre propre mot de passe</a> avant de mettre le logiciel en ligne.</div></div>
    @endif
    @if ($securite)
        <div class="alert {{ collect($securite)->contains('niveau', 'critique') ? 'alert-danger' : 'alert-warning' }}" role="alert">
            <div class="d-flex gap-2 align-items-center mb-1"><i class="bi bi-shield-lock fs-5"></i><strong>Sécurité de l'installation : {{ count($securite) }} point(s) à revoir</strong></div>
            <ul class="mb-1 small">
                @foreach ($securite as $s)<li><strong>{{ $s['titre'] }}</strong> — {{ $s['conseil'] }}</li>@endforeach
            </ul>
            <div class="small text-doux">Contrôle complet sur le serveur : <code>php artisan securite:verifier</code> (mis à jour toutes les heures).</div>
        </div>
    @endif
    @if (! \App\Support\Plateforme::get('telephone') && ! \App\Support\Plateforme::get('infos_paiement'))
        <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Renseignez <a href="{{ route('admin.parametres.edit') }}" class="alert-link">vos coordonnées et comment payer</a> :
            vos clients les verront pour renouveler leur licence.</div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-lg-5"><div class="kpi-principal">
            <div class="etiquette">Encaissé ce mois (licences)</div>
            <div class="valeur montant">{{ gnf($encaisseMois) }}</div>
            <div class="d-flex flex-wrap gap-4 mt-3">
                <div><div class="etiquette small">Depuis janvier</div><div class="fw-bold fs-6 montant">{{ gnf($encaisseAnnee) }}</div></div>
                <div><div class="etiquette small">Valeur mensuelle des licences actives</div><div class="fw-bold fs-6 montant">{{ gnf($revenuMensuel) }}</div></div>
            </div>
        </div></div>
        <div class="col-lg-7"><div class="bloc h-100 row g-0">
            <a href="{{ route('admin.boutiques.index', ['etat' => 'actif']) }}" class="col-6 col-md-3 kpi border-end text-reset"><div class="etiquette">Licences payées</div><div class="valeur">{{ $nb['actives'] }}</div></a>
            <a href="{{ route('admin.boutiques.index', ['etat' => 'essai']) }}" class="col-6 col-md-3 kpi border-end text-reset"><div class="etiquette">En essai</div><div class="valeur">{{ $nb['essai'] }}</div></a>
            <a href="{{ route('admin.boutiques.index', ['etat' => 'expire']) }}" class="col-6 col-md-3 kpi border-end text-reset"><div class="etiquette">Expirées / suspendues</div><div class="valeur text-danger">{{ $nb['inactives'] }}</div></a>
            <a href="{{ route('admin.demandes.index') }}" class="col-6 col-md-3 kpi text-reset"><div class="etiquette">Demandes non lues</div><div class="valeur {{ $nb['demandes'] ? 'text-danger' : '' }}">{{ $nb['demandes'] }}</div></a>
            <div class="col-12 kpi border-top small text-doux">{{ $nb['total'] }} client(s) · {{ $nb['utilisateurs'] }} utilisateur(s) actif(s) · {{ $ventesPlateforme }} vente(s) enregistrée(s) ce mois sur la plateforme</div>
        </div></div>
    </div>
    <div class="row g-3">
        <div class="col-lg-6"><div class="bloc h-100">
            <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-alarm me-1"></i>Licences à relancer (≤ 7 jours ou expirées)</h2></div>
            <table class="table"><tbody>
                @forelse ($expirentBientot as $b)
                    @php($j = $b->joursRestants())
                    <tr><td><a href="{{ route('admin.boutiques.show', $b) }}" class="fw-semibold">{{ $b->nom }}</a>
                            <div class="small text-doux">{{ collect([$b->responsable_nom, $b->responsable_telephone ?: $b->telephone])->filter()->implode(' · ') }}</div></td>
                        <td>{{ $b->libelleStatut() }}</td>
                        <td class="text-end {{ $j < 0 ? 'text-danger' : '' }}">{{ $j < 0 ? 'expirée depuis '.abs($j).' j' : ($j === 0 ? "aujourd'hui" : 'dans '.$j.' j') }}</td>
                        <td class="text-end" style="width:1%">
                            @if ($wa = lien_whatsapp($b->responsable_telephone ?: $b->telephone, \App\Support\MessagesClient::relance($b)))
                                <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn btn-sm btn-success" title="Relancer par WhatsApp"><i class="bi bi-whatsapp"></i></a>
                            @endif</td></tr>
                @empty
                    <tr><td class="vide">Aucune licence à relancer.</td></tr>
                @endforelse
            </tbody></table>
        </div></div>
        <div class="col-lg-6"><div class="bloc h-100">
            <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-chat-dots me-1"></i>Demandes d'assistance</h2><a href="{{ route('admin.demandes.index') }}" class="small">Toutes</a></div>
            <table class="table"><tbody>
                @forelse ($demandes as $d)
                    <tr class="{{ $d->lue_proprietaire ? '' : 'fw-semibold' }}"><td><a href="{{ route('admin.demandes.show', $d) }}">{{ $d->sujet }}</a>
                            <div class="small text-doux fw-normal">{{ $d->boutique?->nom }}</div></td>
                        <td><span class="etat {{ $d->classeStatut() }}">{{ $d->libelleStatut() }}</span></td></tr>
                @empty
                    <tr><td class="vide">Aucune demande en cours.</td></tr>
                @endforelse
            </tbody></table>
        </div></div>
        <div class="col-lg-6"><div class="bloc h-100">
            <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-cash-coin me-1"></i>Derniers paiements</h2><a href="{{ route('admin.paiements.index') }}" class="small">Tous</a></div>
            <table class="table"><tbody>
                @forelse ($derniersPaiements as $p)
                    <tr><td>{{ $p->boutique?->nom }}<div class="small text-doux">{{ $p->numero }} · {{ $p->paye_le->format('d/m/Y') }}</div></td>
                        <td class="text-end montant fw-semibold">{{ gnf($p->montant) }}</td></tr>
                @empty
                    <tr><td class="vide">Aucun paiement enregistré.</td></tr>
                @endforelse
            </tbody></table>
        </div></div>
        <div class="col-lg-6"><div class="bloc h-100">
            <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-shop me-1"></i>Derniers clients</h2><a href="{{ route('admin.boutiques.index') }}" class="small">Tous</a></div>
            <table class="table"><tbody>
                @forelse ($recentes as $b)
                    <tr><td><a href="{{ route('admin.boutiques.show', $b) }}" class="fw-semibold">{{ $b->nom }}</a><div class="small text-doux">{{ $b->ville }}</div></td>
                        <td>{{ $b->plan?->nom }}</td><td class="text-end text-doux">{{ $b->created_at->format('d/m/Y') }}</td></tr>
                @empty
                    <tr><td class="vide">Aucun client pour l'instant.</td></tr>
                @endforelse
            </tbody></table>
        </div></div>
    </div>
@endsection
