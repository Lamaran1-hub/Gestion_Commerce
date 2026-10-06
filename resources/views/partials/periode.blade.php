{{-- Filtre de dates réutilisable : du / au --}}
<div class="col-6 col-md-auto">
    <label class="form-label small text-doux mb-1" for="du">Du</label>
    <input type="date" name="du" id="du" value="{{ request('du', $du ?? '') }}" class="form-control form-control-sm">
</div>
<div class="col-6 col-md-auto">
    <label class="form-label small text-doux mb-1" for="au">Au</label>
    <input type="date" name="au" id="au" value="{{ request('au', $au ?? '') }}" class="form-control form-control-sm">
</div>
