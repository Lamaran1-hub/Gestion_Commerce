@extends('layouts.app')
@section('titre', 'Sauvegardes')
@section('contenu')
    <div class="entete-page">
        <div><h1>Sauvegardes de la base</h1>
            <div class="text-doux">Une sauvegarde complète est faite chaque nuit à 2 h ; les {{ $garder }} plus récentes sont conservées.</div></div>
        <form method="post" action="{{ route('admin.sauvegardes.store') }}" data-sans-confirmation>@csrf
            <button class="btn btn-primary"><i class="bi bi-database-down me-1"></i>Sauvegarder maintenant</button></form>
    </div>
    <div class="alert alert-info small"><i class="bi bi-shield-lock me-1"></i>
        Ces fichiers contiennent toutes les données de vos clients : conservez-les en lieu sûr (clé USB, disque externe, stockage en ligne privé)
        et ne les partagez jamais. <strong>Restauration :</strong> importez le fichier <code>.sql.gz</code> dans phpMyAdmin (onglet « Importer »).</div>
    <div class="bloc"><div class="table-responsive"><table class="table">
        <thead><tr><th>Fichier</th><th>Date</th><th class="text-end">Taille</th><th></th></tr></thead>
        <tbody>
        @forelse ($sauvegardes as $s)
            <tr><td class="fw-semibold">{{ $s['nom'] }}</td><td>{{ $s['date']->format('d/m/Y H:i') }} <span class="small text-doux">({{ $s['date']->diffForHumans() }})</span></td>
                <td class="text-end">{{ number_format($s['taille'] / 1024, 0, ',', ' ') }} Ko</td>
                <td class="text-end text-nowrap">
                    <a href="{{ route('admin.sauvegardes.telecharger', $s['nom']) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1"></i>Télécharger</a>
                    <form method="post" action="{{ route('admin.sauvegardes.destroy', $s['nom']) }}" class="d-inline" data-confirmer="Supprimer la sauvegarde {{ $s['nom'] }} ?">@csrf @method('delete')
                        <button class="btn btn-sm btn-light text-danger" aria-label="Supprimer"><i class="bi bi-trash"></i></button></form></td></tr>
        @empty
            <tr><td colspan="4" class="vide"><i class="bi bi-database"></i>Aucune sauvegarde pour l'instant.</td></tr>
        @endforelse
        </tbody>
    </table></div></div>
@endsection
