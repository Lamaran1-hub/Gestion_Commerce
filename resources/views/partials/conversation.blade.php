{{-- Fil de messages d'une demande d'assistance ; $cote = 'proprietaire' ou 'boutique' (côté du lecteur) --}}
<div class="vstack gap-3">
    @foreach ($demande->messages as $m)
        @php($deMoi = $cote === 'proprietaire' ? $m->du_proprietaire : ! $m->du_proprietaire)
        <div class="d-flex {{ $deMoi ? 'justify-content-end' : '' }}">
            <div class="message {{ $deMoi ? 'message-moi' : '' }}">
                <div class="small fw-semibold mb-1">{{ $m->du_proprietaire ? ($cote === 'proprietaire' ? 'Vous' : \App\Support\Plateforme::get('societe', 'Support')) : ($m->auteur?->nomComplet() ?? 'Utilisateur') }}
                    <span class="text-doux fw-normal">· {{ $m->created_at->format('d/m/Y H:i') }}</span></div>
                <div style="white-space:pre-line">{{ $m->contenu }}</div>
            </div>
        </div>
    @endforeach
</div>
