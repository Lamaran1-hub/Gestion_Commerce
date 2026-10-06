{{-- Gabarit de tous les e-mails du logiciel : en français, signé par l'éditeur --}}
<x-mail::message>
# {{ $greeting ?? ($level === 'error' ? 'Attention' : 'Bonjour,') }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<?php $couleurBouton = in_array($level, ['success', 'error'], true) ? $level : 'primary'; ?>
<x-mail::button :url="$actionUrl" :color="$couleurBouton">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}
@else
Cordialement,<br>
L'équipe {{ \App\Support\Plateforme::get('societe', config('app.name')) }}
@endif

@isset($actionText)
<x-slot:subcopy>
Si le bouton « {{ $actionText }} » ne fonctionne pas, copiez ce lien dans votre navigateur :
<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
