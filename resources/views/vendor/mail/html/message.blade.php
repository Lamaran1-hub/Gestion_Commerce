@php
    $societe = \App\Support\Plateforme::get('societe', config('app.name'));
    $coordonnees = collect([\App\Support\Plateforme::get('adresse'), \App\Support\Plateforme::get('telephone') ? 'Tél. '.\App\Support\Plateforme::get('telephone') : null,
        \App\Support\Plateforme::get('whatsapp') ? 'WhatsApp '.\App\Support\Plateforme::get('whatsapp') : null, \App\Support\Plateforme::get('email'),
        \App\Support\Plateforme::get('site')])->filter()->implode(' · ');
@endphp
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ $societe }}
</x-mail::header>
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
**{{ $societe }}**<br>
{{ $coordonnees }}

Vous recevez cet e-mail car vous utilisez le logiciel {{ config('app.name') }}. Vos préférences d'e-mails se règlent dans votre profil.

© {{ date('Y') }} {{ $societe }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
