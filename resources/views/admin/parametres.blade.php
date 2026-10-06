@extends('layouts.app')
@section('titre', 'Ma société')
@section('contenu')
    @php($v = fn ($cle, $defaut = '') => old($cle, $valeurs[$cle] ?? $defaut))
    <div class="entete-page"><div><h1>Ma société et mon logiciel</h1>
        <div class="text-doux">Votre marque remplace tout nom générique : écran de connexion, onglet du navigateur, documents, messages et reçus.</div></div></div>
    <form method="post" action="{{ route('admin.parametres.update') }}" enctype="multipart/form-data" class="row g-4">
        @csrf @method('put')
        <div class="col-xl-4">
            <div class="bloc bloc-corps">
                <h2 class="h6"><i class="bi bi-window me-1"></i>Mon logiciel</h2>
                <label class="form-label" for="nom_logiciel">Nom du logiciel</label>
                <input name="nom_logiciel" id="nom_logiciel" value="{{ $v('nom_logiciel', config('app.name')) }}" class="form-control" required maxlength="60">
                <div class="form-text">Ex. : « Boutik Pro », « Caisse Plus »…</div>
                <div class="d-flex align-items-center gap-3 mt-3">
                    <img src="{{ logo_plateforme() }}" alt="Logo du logiciel" style="width:72px;height:72px;object-fit:contain" class="border rounded p-1 bg-white">
                    <div class="small text-doux">Affiché avant la connexion, dans l'onglet du navigateur, dans votre espace et chez les clients sans licence payée.</div>
                </div>
                <label class="form-label mt-3" for="logo_logiciel">Logo du logiciel (PNG ou JPG, 1 Mo max)</label>
                <input type="file" name="logo_logiciel" id="logo_logiciel" accept="image/png,image/jpeg,image/webp" class="form-control">
                @if (! empty($valeurs['logo']))
                    <div class="form-check mt-2"><input type="checkbox" name="retirer_logo" value="1" id="retirer_logo" class="form-check-input">
                        <label for="retirer_logo" class="form-check-label">Revenir au logo générique</label></div>
                @endif
            </div>
            <div class="bloc bloc-corps mt-3 row g-3">
                <div class="col-12"><h2 class="h6 mb-0"><i class="bi bi-patch-check me-1"></i>Règles des licences</h2></div>
                <div class="col-6"><label class="form-label" for="jours_essai">Essai gratuit (jours)</label>
                    <input type="number" min="0" max="90" name="jours_essai" id="jours_essai" value="{{ $v('jours_essai', config('gestion.jours_essai')) }}" class="form-control">
                    <div class="form-text">Pour les nouvelles boutiques.</div></div>
                <div class="col-6"><label class="form-label" for="jours_grace">Délai de grâce (jours)</label>
                    <input type="number" min="0" max="30" name="jours_grace" id="jours_grace" value="{{ $v('jours_grace', 0) }}" class="form-control">
                    <div class="form-text">Après l'échéance, avant la coupure.</div></div>
                <div class="col-12">
                    <span class="form-label d-block">Moyens de paiement en ligne acceptés (Djomy)</span>
                    @php($moyensActifs = \App\Support\MoyensDjomy::actifs())
                    <input type="hidden" name="djomy_moyens_envoye" value="1">
                    <div class="row g-1">
                        @foreach (\App\Support\MoyensDjomy::CATALOGUE as $code => $infos)
                            <div class="col-6"><div class="form-check">
                                <input type="checkbox" name="djomy_moyens[]" value="{{ $code }}" id="moyen_{{ $code }}" class="form-check-input" @checked(in_array($code, old('djomy_moyens', $moyensActifs), true))>
                                <label for="moyen_{{ $code }}" class="form-check-label d-flex align-items-center gap-1">@include('partials.badge-moyen', ['code' => $code]){{ $infos[0] }}
                                    @unless ($infos[6])<small class="text-doux">(bientôt)</small>@endunless</label></div></div>
                        @endforeach
                    </div>
                    <div class="form-text">Activez uniquement les moyens ouverts sur votre compte marchand Djomy.
                        YMO existe dans l'API Djomy mais n'est pas encore proposé sur leur page de paiement : activez-le quand Djomy le confirme.</div>
                </div>
                <div class="col-12"><div class="form-check form-switch">
                    <input type="hidden" name="activation_auto" value="0">
                    <input type="checkbox" name="activation_auto" value="1" id="activation_auto" class="form-check-input" @checked($v('activation_auto', '1') === '1')>
                    <label for="activation_auto" class="form-check-label">Activer la licence automatiquement après un paiement en ligne vérifié</label></div>
                    <div class="form-text">Désactivé : vous êtes notifié et validez chaque paiement vous-même avant d'envoyer la licence.</div></div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="bloc bloc-corps row g-3">
                <div class="col-12"><h2 class="h6 mb-0"><i class="bi bi-building me-1"></i>Ma société (éditeur)</h2></div>
                <div class="col-md-6"><label class="form-label" for="societe">Nom de votre société</label>
                    <input name="societe" id="societe" value="{{ $v('societe') }}" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label" for="site">Site web</label>
                    <input name="site" id="site" value="{{ $v('site') }}" class="form-control"></div>
                <div class="col-md-4"><label class="form-label" for="telephone">Téléphone</label>
                    <input name="telephone" id="telephone" value="{{ $v('telephone') }}" class="form-control"></div>
                <div class="col-md-4"><label class="form-label" for="whatsapp">WhatsApp</label>
                    <input name="whatsapp" id="whatsapp" value="{{ $v('whatsapp') }}" class="form-control" placeholder="+224…"></div>
                <div class="col-md-4"><label class="form-label" for="email">E-mail</label>
                    <input type="email" name="email" id="email" value="{{ $v('email') }}" class="form-control"></div>
                <div class="col-12"><label class="form-label" for="adresse">Adresse</label>
                    <input name="adresse" id="adresse" value="{{ $v('adresse') }}" class="form-control"></div>
                <div class="col-12"><label class="form-label" for="infos_paiement">Comment payer la licence</label>
                    <textarea name="infos_paiement" id="infos_paiement" rows="4" class="form-control" placeholder="Ex. : Orange Money au 6XX XX XX XX (au nom de votre société), ou virement sur le compte…">{{ $v('infos_paiement') }}</textarea>
                    <div class="form-text">Affiché à vos clients sur leur page « Ma licence » et dans les relances WhatsApp.</div></div>
            </div>
            <div class="mt-3"><button class="btn btn-primary btn-lg">Enregistrer</button></div>
        </div>
    </form>
@endsection
