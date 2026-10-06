@extends('layouts.app')
@section('titre', 'E-mails')
@section('contenu')
    <div class="entete-page">
        <div><h1>E-mails</h1>
            <div class="text-doux">Bienvenue, confirmations de paiement avec reçu, rappels d'échéance, suspension, nouveautés : tout part d'ici, à vos couleurs.</div></div>
        @if ($reel)<span class="etat etat-ok align-self-center">Envoi réel activé</span>@else<span class="etat etat-alerte align-self-center">Envoi simulé (non configuré)</span>@endif
    </div>

    <div class="row g-3 mb-3">
        @foreach (\App\Models\EmailEnvoye::STATUTS as $cle => $libelle)
            <div class="col-md-4"><div class="bloc kpi"><div class="etiquette">{{ $libelle }} (30 jours)</div>
                <div class="valeur {{ $cle === 'echec' && ($stats[$cle] ?? 0) ? 'text-danger' : '' }}">{{ $stats[$cle] ?? 0 }}</div></div></div>
        @endforeach
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <form method="post" action="{{ route('admin.emails.configurer') }}" class="bloc" data-sans-confirmation>
                @csrf
                <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-server me-1"></i>Serveur d'envoi (SMTP)</h2></div>
                <div class="bloc-corps row g-2">
                    <div class="col-12"><div class="form-check form-switch">
                        <input type="checkbox" name="emails_actifs" value="1" id="emails_actifs" class="form-check-input" @checked(old('emails_actifs', ($p['emails_actifs'] ?? '0') === '1'))>
                        <label for="emails_actifs" class="form-check-label fw-semibold">Envoyer réellement les e-mails</label></div></div>
                    <div class="col-md-8"><label class="form-label small" for="smtp_hote">Serveur</label>
                        <input name="smtp_hote" id="smtp_hote" value="{{ old('smtp_hote', $p['smtp_hote'] ?? '') }}" class="form-control" placeholder="smtp.gmail.com, mail.votre-domaine.com…"></div>
                    <div class="col-md-4"><label class="form-label small" for="smtp_port">Port</label>
                        <input type="number" name="smtp_port" id="smtp_port" value="{{ old('smtp_port', $p['smtp_port'] ?? 587) }}" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label small" for="smtp_chiffrement">Chiffrement</label>
                        <select name="smtp_chiffrement" id="smtp_chiffrement" class="form-select">
                            @foreach (['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'aucun' => 'Aucun'] as $k => $l)
                                <option value="{{ $k }}" @selected(old('smtp_chiffrement', $p['smtp_chiffrement'] ?? 'tls') === $k)>{{ $l }}</option>@endforeach
                        </select></div>
                    <div class="col-md-4"><label class="form-label small" for="smtp_utilisateur">Identifiant</label>
                        <input name="smtp_utilisateur" id="smtp_utilisateur" value="{{ old('smtp_utilisateur', $p['smtp_utilisateur'] ?? '') }}" class="form-control" autocomplete="off"></div>
                    <div class="col-md-4"><label class="form-label small" for="smtp_mot_de_passe">Mot de passe</label>
                        <input type="password" name="smtp_mot_de_passe" id="smtp_mot_de_passe" class="form-control" autocomplete="new-password"
                               placeholder="{{ ! empty($p['smtp_mot_de_passe']) ? '•••••• (inchangé)' : '' }}"></div>
                    <div class="col-md-6"><label class="form-label small" for="expediteur_email">Adresse d'expédition</label>
                        <input type="email" name="expediteur_email" id="expediteur_email" value="{{ old('expediteur_email', $p['expediteur_email'] ?? '') }}" class="form-control" placeholder="contact@votre-domaine.com"></div>
                    <div class="col-md-6"><label class="form-label small" for="expediteur_nom">Nom d'expéditeur</label>
                        <input name="expediteur_nom" id="expediteur_nom" value="{{ old('expediteur_nom', $p['expediteur_nom'] ?? '') }}" class="form-control" placeholder="{{ $p['societe'] ?? config('app.name') }}"></div>
                    <div class="col-12 form-text">Le mot de passe est chiffré. Avec Gmail, utilisez un « mot de passe d'application ». Pour une présentation professionnelle,
                        utilisez une adresse de votre propre domaine. Logo et coordonnées des e-mails : menu « Ma société (éditeur) ».</div>
                    <div class="col-12"><button class="btn btn-primary">Enregistrer</button></div>
                </div>
            </form>
        </div>
        <div class="col-lg-5">
            <form method="post" action="{{ route('admin.emails.tester') }}" class="bloc bloc-corps mb-3" data-sans-confirmation>
                @csrf
                <h2 class="h6"><i class="bi bi-send-check me-1"></i>Envoyer un e-mail de test</h2>
                <div class="input-group">
                    <input type="email" name="email_test" value="{{ old('email_test', auth()->user()->email) }}" class="form-control" required aria-label="Adresse de test">
                    <button class="btn btn-outline-primary">Envoyer</button>
                </div>
            </form>
            <div class="bloc bloc-corps small">
                <h2 class="h6">E-mails envoyés automatiquement</h2>
                <ul class="mb-0 ps-3">
                    <li><strong>Bienvenue</strong> à chaque nouvelle boutique (sans mot de passe).</li>
                    <li><strong>Paiement reçu</strong>, en ligne ou au guichet, avec le reçu PDF joint.</li>
                    <li><strong>Rappels d'échéance</strong> à J-7, J-3, J-1, le jour J et après expiration.</li>
                    <li><strong>Suspension</strong> et <strong>réactivation</strong> d'une boutique.</li>
                    <li><strong>Nouveautés</strong> : cochez « Envoyer aussi par e-mail » dans une annonce (désabonnement en un clic).</li>
                    <li><strong>Résumé du jour</strong> pour les boutiques qui l'ont activé.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="bloc">
        <div class="bloc-entete flex-wrap gap-2"><h2 class="mb-0">Journal des e-mails</h2>
            <form class="d-flex flex-wrap gap-2">
                <input name="q" value="{{ request('q') }}" class="form-control form-control-sm" style="width:200px" placeholder="Destinataire ou sujet" aria-label="Rechercher">
                <select name="type" class="form-select form-select-sm" style="width:auto" aria-label="Type"><option value="">Tous les types</option>
                    @foreach (\App\Models\EmailEnvoye::TYPES as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
                <select name="statut" class="form-select form-select-sm" style="width:auto" aria-label="Statut"><option value="">Tous</option>
                    @foreach (\App\Models\EmailEnvoye::STATUTS as $k => $l)<option value="{{ $k }}" @selected(request('statut') === $k)>{{ $l }}</option>@endforeach</select>
                <button class="btn btn-sm btn-outline-primary">Filtrer</button>
            </form></div>
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Date</th><th>Destinataire</th><th>Sujet</th><th>Type</th><th>Boutique</th><th>Statut</th></tr></thead>
            <tbody>
            @forelse ($journal as $e)
                <tr><td class="small text-nowrap">{{ $e->created_at->format('d/m/Y H:i') }}</td><td class="small">{{ $e->destinataire }}</td>
                    <td class="small">{{ $e->sujet }}@if ($e->erreur)<div class="text-danger">{{ \Illuminate\Support\Str::limit($e->erreur, 160) }}</div>@endif</td>
                    <td class="small">{{ \App\Models\EmailEnvoye::TYPES[$e->type] ?? $e->type }}</td>
                    <td class="small">{{ $e->boutique?->nom ?? '—' }}</td>
                    <td><span class="etat {{ ['envoye' => 'etat-ok', 'simule' => 'etat-alerte'][$e->statut] ?? 'etat-rupture' }}">{{ \App\Models\EmailEnvoye::STATUTS[$e->statut] ?? $e->statut }}</span></td></tr>
            @empty
                <tr><td colspan="6" class="vide">Aucun e-mail pour l'instant.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
    <div class="mt-2">{{ $journal->links() }}</div>
@endsection
