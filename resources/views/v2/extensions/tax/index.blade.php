@extends('layout.v2')
@section('content')
    <div class="app-content">
        <div class="container-fluid" x-data="index">
            <x-messages></x-messages>

            {{-- Profile list --}}
            <div class="row mb-3">
                <div class="col-xl-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">{{ __('firefly.tax_profiles') }}</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-primary btn-sm" @click="showModal = true">
                                    <i class="fa-solid fa-plus"></i>
                                    {{ __('firefly.new_tax_profile') }}
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('firefly.name') }}</th>
                                        <th>{{ __('firefly.tax_year') }}</th>
                                        <th>{{ __('firefly.tax_rate') }}</th>
                                        <th>{{ __('firefly.notes') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr x-show="isLoading">
                                        <td colspan="5" class="text-center py-3">
                                            <span class="fa fa-spin fa-spinner"></span>
                                        </td>
                                    </tr>
                                    <tr x-show="!isLoading && profiles.length === 0">
                                        <td colspan="5" class="text-center py-3 text-muted">
                                            {{ __('firefly.no_tax_profiles') }}
                                        </td>
                                    </tr>
                                    <template x-for="profile in profiles" :key="profile.id">
                                        <tr>
                                            <td>
                                                <a :href="'./tax/' + profile.id" x-text="profile.attributes.name"></a>
                                            </td>
                                            <td x-text="profile.attributes.tax_year"></td>
                                            <td>
                                                <span x-show="profile.attributes.tax_rate > 0" x-text="profile.attributes.tax_rate + '%'"></span>
                                                <span x-show="profile.attributes.tax_rate == 0" class="text-muted">—</span>
                                            </td>
                                            <td>
                                                <span x-text="profile.attributes.notes ? profile.attributes.notes.substring(0, 60) : ''"></span>
                                                <span x-show="profile.attributes.notes && profile.attributes.notes.length > 60">…</span>
                                            </td>
                                            <td class="text-end">
                                                <a :href="'./tax/' + profile.id" class="btn btn-default btn-sm">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <button type="button" class="btn btn-danger btn-sm"
                                                        @click="deleteProfile(profile.id)">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Create profile modal --}}
            <div class="modal fade" id="createProfileModal" tabindex="-1"
                 x-bind:class="{ show: showModal, 'd-block': showModal }"
                 x-show="showModal" x-cloak>
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('firefly.new_tax_profile') }}</h5>
                            <button type="button" class="btn-close" @click="showModal = false"></button>
                        </div>
                        <div class="modal-body">
                            {{-- Name --}}
                            <div class="mb-3">
                                <label class="form-label">{{ __('firefly.name') }}</label>
                                <input type="text" class="form-control"
                                       x-model="form.name"
                                       :class="{ 'is-invalid': formErrors.name.length > 0 }">
                                <template x-if="formErrors.name.length > 0">
                                    <div class="invalid-feedback" x-text="formErrors.name[0]"></div>
                                </template>
                            </div>
                            {{-- Tax year --}}
                            <div class="mb-3">
                                <label class="form-label">{{ __('firefly.tax_year') }}</label>
                                <input type="number" class="form-control"
                                       x-model="form.tax_year"
                                       :class="{ 'is-invalid': formErrors.tax_year.length > 0 }">
                                <template x-if="formErrors.tax_year.length > 0">
                                    <div class="invalid-feedback" x-text="formErrors.tax_year[0]"></div>
                                </template>
                            </div>
                            {{-- Tax rate --}}
                            <div class="mb-3">
                                <label class="form-label">{{ __('firefly.tax_rate') }} (%)</label>
                                <input type="number" step="0.01" min="0" max="100" class="form-control"
                                       x-model="form.tax_rate"
                                       :class="{ 'is-invalid': formErrors.tax_rate.length > 0 }">
                                <template x-if="formErrors.tax_rate.length > 0">
                                    <div class="invalid-feedback" x-text="formErrors.tax_rate[0]"></div>
                                </template>
                            </div>
                            {{-- Notes --}}
                            <div class="mb-3">
                                <label class="form-label">{{ __('firefly.notes') }}</label>
                                <textarea class="form-control" rows="3" x-model="form.notes"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" @click="showModal = false">
                                {{ __('firefly.cancel') }}
                            </button>
                            <button type="button" class="btn btn-primary" @click="createProfile()"
                                    :disabled="isSubmitting">
                                <span x-show="isSubmitting" class="fa fa-spin fa-spinner me-1"></span>
                                {{ __('firefly.save') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-backdrop fade show" x-show="showModal" x-cloak></div>

        </div>
    </div>
@endsection
@section('scripts')
    @vite(['src/pages/extensions/tax/index.js'])
@endsection
