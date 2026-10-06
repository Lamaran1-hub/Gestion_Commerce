@foreach (['succes' => 'success', 'erreur' => 'danger', 'info' => 'info'] as $cle => $classe)
    @if (session($cle))
        <div class="alert alert-{{ $classe }} alert-dismissible fade show" role="alert">
            {{ session($cle) }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    @endif
@endforeach
@if (session('identifiants'))
    {{-- Identifiants générés : affichés une seule fois, à transmettre à l'utilisateur --}}
    <div class="alert alert-warning" role="alert">
        <div class="fw-bold mb-1"><i class="bi bi-key me-1"></i>Identifiants à transmettre (ils ne seront plus affichés)</div>
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span>E-mail : <code class="fs-6" id="idEmail">{{ session('identifiants.email') }}</code></span>
            <span>Mot de passe provisoire : <code class="fs-6" id="idMdp">{{ session('identifiants.mot_de_passe') }}</code></span>
            <button type="button" class="btn btn-sm btn-light" onclick="navigator.clipboard?.writeText('E-mail : ' + document.getElementById('idEmail').textContent + '\nMot de passe : ' + document.getElementById('idMdp').textContent); this.textContent = 'Copié ✓'">
                <i class="bi bi-clipboard me-1"></i>Copier</button>
            @php($wa = lien_whatsapp(session('identifiants.telephone'), \App\Support\MessagesClient::identifiants(
                session('identifiants.nom', ''), session('identifiants.email'), session('identifiants.mot_de_passe'))))
            @if ($wa)<a href="{{ $wa }}" target="_blank" rel="noopener" class="btn btn-sm btn-success"><i class="bi bi-whatsapp me-1"></i>Envoyer par WhatsApp</a>@endif
        </div>
        <div class="small mt-1">L'utilisateur pourra le changer depuis « Mon profil » (ce n'est pas obligatoire).</div>
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Vérifiez le formulaire :</strong>
        <ul class="mb-0 mt-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif
