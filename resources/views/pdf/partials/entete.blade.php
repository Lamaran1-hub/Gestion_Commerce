{{-- En-tête commun des documents PDF : logo, coordonnées de l'entreprise et bandeau aux couleurs de la boutique --}}
@php($coul = $boutique->couleurs())
<table style="width:100%;border-collapse:collapse;margin:0 0 4px">
    <tr>
        <td style="border:0;padding:0;vertical-align:top;width:60%">
            <table style="border-collapse:collapse;margin:0;width:auto"><tr>
                @if ($boutique->logoChemin())
                    <td style="border:0;padding:0 10px 0 0;vertical-align:top"><img src="{{ $boutique->logoChemin() }}" style="max-height:58px;max-width:130px"></td>
                @endif
                <td style="border:0;padding:0;vertical-align:top">
                    <div style="font-weight:bold;font-size:14px;color:{{ $boutique->couleur }}">{{ $boutique->nom }}</div>
                    @foreach ($boutique->coordonnees() as $ligne)<div style="color:#5E6B65;font-size:9px">{{ $ligne }}</div>@endforeach
                </td>
            </tr></table>
        </td>
        <td style="border:0;padding:0;vertical-align:top;text-align:right">{{ $slot ?? '' }}</td>
    </tr>
</table>
<table style="width:100%;border-collapse:collapse;margin:6px 0 10px"><tr>
    @foreach ($coul as $c)<td style="border:0;padding:0;height:4px;background:{{ $c }};font-size:1px;line-height:1px">&nbsp;</td>@endforeach
</tr></table>
