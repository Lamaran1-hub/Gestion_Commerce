{{-- Informations sur le client qui achète le logiciel (création et modification) --}}
@php($b = $boutique ?? null)
<div class="col-12"><h2 class="h6 mb-0"><i class="bi bi-shop me-1"></i>Entreprise cliente</h2></div>
<div class="col-md-8"><label class="form-label" for="nom">Nom de la boutique / entreprise</label>
    <input name="nom" id="nom" value="{{ old('nom', $b?->nom) }}" class="form-control" required></div>
<div class="col-md-4"><label class="form-label" for="secteur">Secteur d'activité</label>
    <input name="secteur" id="secteur" value="{{ old('secteur', $b?->secteur) }}" class="form-control" list="secteurs" placeholder="Quincaillerie, alimentation…">
    <datalist id="secteurs">@foreach (['Alimentation', 'Quincaillerie', 'Pharmacie', 'Boutique de vêtements', 'Cosmétiques', 'Électronique', 'Pièces détachées', 'Librairie', 'Restaurant'] as $s)<option value="{{ $s }}">@endforeach</datalist></div>
<div class="col-md-6"><label class="form-label" for="responsable_nom">Responsable (gérant, propriétaire)</label>
    <input name="responsable_nom" id="responsable_nom" value="{{ old('responsable_nom', $b?->responsable_nom) }}" class="form-control"></div>
<div class="col-md-6"><label class="form-label" for="responsable_telephone">Téléphone du responsable</label>
    <input name="responsable_telephone" id="responsable_telephone" value="{{ old('responsable_telephone', $b?->responsable_telephone) }}" class="form-control"></div>
<div class="col-md-6"><label class="form-label" for="telephone">Téléphone de la boutique</label>
    <input name="telephone" id="telephone" value="{{ old('telephone', $b?->telephone) }}" class="form-control"></div>
<div class="col-md-6"><label class="form-label" for="email">E-mail</label>
    <input type="email" name="email" id="email" value="{{ old('email', $b?->email) }}" class="form-control"></div>
<div class="col-md-8"><label class="form-label" for="adresse">Adresse (quartier, commune)</label>
    <input name="adresse" id="adresse" value="{{ old('adresse', $b?->adresse) }}" class="form-control"></div>
<div class="col-md-4"><label class="form-label" for="ville">Ville</label>
    <input name="ville" id="ville" value="{{ old('ville', $b?->ville) }}" class="form-control"></div>
<div class="col-md-6"><label class="form-label" for="rccm">RCCM</label>
    <input name="rccm" id="rccm" value="{{ old('rccm', $b?->rccm) }}" class="form-control"></div>
<div class="col-md-6"><label class="form-label" for="nif">NIF</label>
    <input name="nif" id="nif" value="{{ old('nif', $b?->nif) }}" class="form-control"></div>
