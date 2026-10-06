<!doctype html>
<html lang="fr">
<head>@include('layouts.tete')<title>{{ '404' }}</title></head>
<body class="d-grid" style="min-height:100vh;place-items:center">
    <div class="text-center p-4" style="max-width:480px">
        <div class="display-4 fw-bold" style="color:var(--marque)">404</div>
        <h1 class="h4 mt-2">{{ '404' === '403' ? 'Vous n\'avez pas accès à cette page' : 'Cette page n\'existe pas' }}</h1>
        <p class="text-doux">{{ '404' === '403' ? ($exception->getMessage() ?: 'Demandez à l\'administrateur de votre boutique de vous donner ce droit.') : 'Le lien est peut-être erroné, ou l\'élément a été supprimé.' }}</p>
        <a href="{{ url('/') }}" class="btn btn-primary">Revenir à l'accueil</a>
    </div>
</body>
</html>
