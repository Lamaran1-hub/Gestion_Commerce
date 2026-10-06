@extends('layouts.app')
@section('titre', 'Intégrité des encaissements')
@section('contenu')
    <div class="entete-page">
        <div><h1>Intégrité des encaissements</h1>
            <div class="text-doux">Chaque vente, paiement, retour, annulation et clôture est signé et chaîné au précédent : aucune modification ne peut passer inaperçue.</div></div>
    </div>

    <div class="bloc bloc-corps mb-3 d-flex align-items-center gap-3">
        @if ($resultat['intact'])
            <i class="bi bi-shield-check fs-1 text-success"></i>
            <div><h2 class="h5 mb-1 text-success">Registre intact</h2>
                <div class="text-doux">{{ $resultat['operations'] }} opération(s) contrôlée(s) : aucune modification ni suppression détectée.</div></div>
        @else
            <i class="bi bi-shield-exclamation fs-1 text-danger"></i>
            <div><h2 class="h5 mb-1 text-danger">{{ count($resultat['anomalies']) }} anomalie(s) détectée(s)</h2>
                <div class="text-doux">Des données d'encaissement ont été modifiées ou supprimées en dehors du logiciel. Conservez cette page et contactez l'éditeur.</div></div>
        @endif
    </div>

    @if ($resultat['anomalies'])
        <div class="bloc mb-3 border-danger">
            <div class="bloc-entete"><h2 class="mb-0 text-danger">Anomalies</h2></div>
            <ul class="bloc-corps mb-0">@foreach ($resultat['anomalies'] as $a)<li>{{ $a }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="bloc mb-3">
        <div class="bloc-entete flex-wrap gap-2"><h2 class="mb-0"><i class="bi bi-archive me-1"></i>Archives fiscales mensuelles</h2>
            <form method="post" action="{{ route('integrite.archiver') }}" class="d-flex gap-2" data-sans-confirmation>@csrf
                <input type="month" name="mois" value="{{ now()->subMonthNoOverflow()->format('Y-m') }}" max="{{ now()->subMonthNoOverflow()->format('Y-m') }}" class="form-control form-control-sm" aria-label="Mois à archiver" required>
                <button class="btn btn-sm btn-outline-primary">Archiver ce mois</button></form></div>
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Mois</th><th class="text-end">Ventes</th><th class="text-end">Total TTC</th><th>Empreinte du fichier</th><th>État</th><th></th></tr></thead>
            <tbody>
            @forelse ($archives as $a)
                <tr><td>{{ ucfirst(\Carbon\Carbon::parse($a->periode_du)->translatedFormat('F Y')) }}</td>
                    <td class="text-end">{{ $a->nb_ventes }}</td><td class="text-end montant">{{ gnf($a->total_ttc) }}</td>
                    <td><code class="small">{{ substr($a->empreinte, 0, 20) }}…</code></td>
                    <td>@if ($a->intacte)<span class="etat etat-ok">Intacte</span>@else<span class="etat etat-rupture">Modifiée ou absente</span>@endif</td>
                    <td class="text-end">@if ($a->intacte)<a href="{{ route('integrite.telecharger', $a->id) }}" class="btn btn-sm btn-light"><i class="bi bi-download"></i></a>@endif</td></tr>
            @empty
                <tr><td colspan="6" class="vide">Aucune archive. Elles sont créées automatiquement le 1er de chaque mois pour le mois écoulé.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="bloc-corps border-top small text-doux">À conserver {{ \App\Services\ArchivesFiscales::DUREE_CONSERVATION_ANS }} ans. Chaque archive contient ventes, lignes, paiements, retours, clôtures et l'extrait du registre signé ;
            son empreinte SHA-256 prouve qu'elle n'a pas été modifiée. Téléchargez-les et gardez-en une copie hors du logiciel.</div>
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="bloc bloc-corps small h-100">
                <h2 class="h6">Comment ça marche</h2>
                <ul class="ps-3">
                    <li><strong>Inaltérabilité</strong> : une opération enregistrée ne se modifie pas ; on la corrige par une autre (retour, annulation), elle-même tracée.</li>
                    <li><strong>Sécurisation</strong> : chaque opération reçoit une empreinte SHA-256 calculée avec celle de la précédente. Changer un seul chiffre casse la chaîne.</li>
                    <li><strong>Conservation</strong> : rien n'est supprimé ; les ventes annulées restent visibles.</li>
                    <li>L'empreinte de chaque vente figure sur son ticket, celle de chaque clôture sur le rapport Z.</li>
                </ul>
                @if ($resultat['anterieures'])<div class="text-doux">{{ $resultat['anterieures'] }} vente(s) antérieure(s) à la mise en place du registre ne sont pas signées.</div>@endif
                @if ($resultat['derniere'])<div class="mt-2">Dernière empreinte : <code class="small">{{ $resultat['derniere'] }}</code></div>@endif
            </div>
        </div>
        <div class="col-lg-7">
            <div class="bloc">
                <div class="bloc-entete"><h2 class="mb-0">Dernières opérations signées</h2></div>
                <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>N°</th><th>Date</th><th>Opération</th><th>Empreinte</th></tr></thead>
                    <tbody>
                    @forelse ($dernieres as $o)
                        <tr><td>{{ $o->sequence }}</td><td class="small">{{ \Carbon\Carbon::parse($o->created_at)->format('d/m/Y H:i') }}</td>
                            <td>{{ \App\Services\Registre::TYPES[$o->type] ?? $o->type }}</td>
                            <td><code class="small">{{ substr($o->empreinte, 0, 16) }}…</code></td></tr>
                    @empty
                        <tr><td colspan="4" class="vide">Aucune opération signée pour l'instant.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
@endsection
