@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="{{ logo_plateforme() }}" class="logo" alt="" style="height: 48px; max-height: 48px; width: auto; vertical-align: middle;">
<span style="vertical-align: middle; margin-left: 8px;">{!! $slot !!}</span>
</a>
</td>
</tr>
