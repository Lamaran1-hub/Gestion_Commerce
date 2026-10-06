@extends('layouts.app')
@section('titre', 'Notifications')
@section('contenu')
    <div class="entete-page"><div><h1>Notifications</h1><div class="text-doux">Messages automatiques du logiciel vous concernant.</div></div></div>
    <div class="bloc" style="max-width:900px">
        @forelse ($notifications as $n)
            <a href="{{ route('notifications.ouvrir', $n->id) }}" class="d-flex gap-3 p-3 border-bottom text-reset text-decoration-none notification-ligne">
                <i class="bi bi-{{ $n->data['icone'] ?? 'bell' }} fs-4 text-doux"></i>
                <div class="flex-grow-1">
                    <div class="fw-semibold">{{ $n->data['titre'] ?? 'Notification' }}
                        @if (in_array($n->id, $nonLues, true))<span class="badge bg-danger ms-1">Nouveau</span>@endif</div>
                    <div class="small" style="white-space:pre-line">{{ $n->data['message'] ?? '' }}</div>
                </div>
                <div class="small text-doux text-nowrap">{{ $n->created_at->diffForHumans() }}</div>
            </a>
        @empty
            <div class="vide"><i class="bi bi-bell"></i>Aucune notification.</div>
        @endforelse
    </div>
    <div class="mt-3">{{ $notifications->links() }}</div>
@endsection
