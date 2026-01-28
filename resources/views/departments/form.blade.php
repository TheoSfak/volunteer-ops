@extends('layouts.app')

@section('title', isset($department) ? 'Επεξεργασία Τμήματος' : 'Νέο Τμήμα')
@section('page-title', isset($department) ? 'Επεξεργασία Τμήματος' : 'Νέο Τμήμα')

@section('content')
    <div class="row">
        <div class="col-lg-6">
            <form action="{{ isset($department) ? route('departments.update', $department) : route('departments.store') }}" method="POST">
                @csrf
                @if(isset($department)) @method('PUT') @endif
                
                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-building me-2"></i>Στοιχεία Τμήματος</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Όνομα Τμήματος <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $department->name ?? '') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="code" class="form-label">Κωδικός</label>
                            <input type="text" class="form-control @error('code') is-invalid @enderror" id="code" name="code" value="{{ old('code', $department->code ?? '') }}" placeholder="π.χ. HEALTH">
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Περιγραφή</label>
                            <textarea class="form-control" id="description" name="description" rows="3">{{ old('description', $department->description ?? '') }}</textarea>
                        </div>
                        <div class="mb-3">
                            <label for="parent_id" class="form-label">Γονικό Τμήμα</label>
                            <select class="form-select" id="parent_id" name="parent_id">
                                <option value="">Κανένα (Κεντρικό τμήμα)</option>
                                @foreach($parentDepartments ?? [] as $parent)
                                    @if(!isset($department) || $parent->id !== $department->id)
                                        <option value="{{ $parent->id }}" {{ old('parent_id', $department->parent_id ?? '') == $parent->id ? 'selected' : '' }}>{{ $parent->name }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" {{ old('is_active', $department->is_active ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="is_active">Ενεργό τμήμα</label>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>{{ isset($department) ? 'Αποθήκευση' : 'Δημιουργία' }}</button>
                    <a href="{{ route('departments.index') }}" class="btn btn-outline-secondary">Ακύρωση</a>
                </div>
            </form>
        </div>
    </div>
@endsection
