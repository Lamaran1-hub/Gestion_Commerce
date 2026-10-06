@php
    // Les annonces importantes et de maintenance non lues s'affichent en haut de chaque page jusqu'à « J'ai compris »
    $annoncesUrgentes = \App\Models\Annonce::pourUtilisateur(auth()->user())->nonLuesPar(auth()->user())
        ->whereIn('type', ['important', 'maintenance'])->latest('publiee_le')->limit(3)->get();
@endphp
@foreach ($annoncesUrgentes as $a)
    <div class="alert {{ $a->type === 'important' ? 'alert-danger' : 'alert-warning' }} d-flex gap-3 align-items-start" role="alert">
        <i class="bi bi-{{ $a->icone() }} fs-4"></i>
        <div class="flex-grow-1">
            <div class="fw-bold">{{ $a->titre }}</div>
            <div class="small" style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($a->contenu, 300) }}</div>
        </div>
        <form method="post" action="{{ route('nouveautes.lire', $a) }}">@csrf
            <button class="btn btn-sm btn-light text-nowrap">J'ai compris</button></form>
    </div>
@endforeach
