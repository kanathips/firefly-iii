@extends('layout.v2')
@section('content')
    <div class="app-content">
        <div class="container-fluid" x-data="show" x-init="init({{ $profileId }})">
            <x-messages></x-messages>

            {{-- Card A: Profile details & linked tags --}}
            <div class="row mb-3">
                <div class="col-xl-6 col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title" x-text="profile.attributes ? profile.attributes.name : '...'"></h3>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-4">{{ __('firefly.tax_year') }}</dt>
                                <dd class="col-sm-8" x-text="profile.attributes ? profile.attributes.tax_year : ''"></dd>
                                <dt class="col-sm-4">{{ __('firefly.tax_rate') }}</dt>
                                <dd class="col-sm-8">
                                    <span x-show="profile.attributes && profile.attributes.tax_rate > 0"
                                          x-text="profile.attributes ? profile.attributes.tax_rate + '%' : ''"></span>
                                    <span x-show="!profile.attributes || profile.attributes.tax_rate == 0" class="text-muted">—</span>
                                </dd>
                                <template x-if="profile.attributes && profile.attributes.notes">
                                    <dt class="col-sm-4">{{ __('firefly.notes') }}</dt>
                                </template>
                                <template x-if="profile.attributes && profile.attributes.notes">
                                    <dd class="col-sm-8" x-text="profile.attributes ? profile.attributes.notes : ''"></dd>
                                </template>
                            </dl>
                        </div>
                    </div>
                </div>

                {{-- Linked tags --}}
                <div class="col-xl-6 col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">{{ __('firefly.tax_deductible_tags') }}</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-hover mb-2" x-show="tags.length > 0">
                                <tbody>
                                    <template x-for="tag in tags" :key="tag.id">
                                        <tr>
                                            <td>
                                                <span class="badge bg-secondary me-1">
                                                    <i class="fa-solid fa-tag"></i>
                                                </span>
                                                <span x-text="tag.tag"></span>
                                            </td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-danger btn-sm"
                                                        @click="removeTag(tag.id)">
                                                    <i class="fa-solid fa-times"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <p x-show="tags.length === 0 && !isLoadingTags" class="text-muted mb-2">
                                {{ __('firefly.no_tags_linked') }}
                            </p>
                            {{-- Add tag --}}
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control"
                                       placeholder="{{ __('firefly.search_tags') }}"
                                       x-model="tagSearch"
                                       @keyup="searchTags()"
                                       @keyup.enter="addTagById()">
                                <button class="btn btn-default" type="button" @click="addTagById()">
                                    <i class="fa-solid fa-plus"></i>
                                    {{ __('firefly.add') }}
                                </button>
                            </div>
                            {{-- Tag suggestions dropdown --}}
                            <div class="list-group mt-1" x-show="tagSuggestions.length > 0">
                                <template x-for="suggestion in tagSuggestions" :key="suggestion.id">
                                    <button type="button"
                                            class="list-group-item list-group-item-action list-group-item-sm"
                                            @click="selectTag(suggestion)">
                                        <i class="fa-solid fa-tag me-1"></i>
                                        <span x-text="suggestion.tag"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Card B: Deductible summary --}}
            <div class="row mb-3">
                <div class="col-xl-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">{{ __('firefly.tax_deductible_summary') }}</h3>
                            <div class="card-tools d-flex align-items-center gap-2">
                                {{-- Date range --}}
                                <input type="date" class="form-control form-control-sm" style="width:150px"
                                       x-model="dateRange.start">
                                <span class="text-muted">—</span>
                                <input type="date" class="form-control form-control-sm" style="width:150px"
                                       x-model="dateRange.end">
                                {{-- Period toggle --}}
                                <div class="btn-group btn-group-sm">
                                    <button type="button"
                                            class="btn"
                                            :class="period === 'month' ? 'btn-primary' : 'btn-default'"
                                            @click="period = 'month'">{{ __('firefly.month') }}</button>
                                    <button type="button"
                                            class="btn"
                                            :class="period === 'year' ? 'btn-primary' : 'btn-default'"
                                            @click="period = 'year'">{{ __('firefly.year') }}</button>
                                </div>
                                <button type="button" class="btn btn-primary btn-sm" @click="loadSummary()"
                                        :disabled="isLoadingSummary">
                                    <span x-show="isLoadingSummary" class="fa fa-spin fa-spinner me-1"></span>
                                    {{ __('firefly.apply') }}
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            {{-- Stat boxes --}}
                            <div class="row mb-3">
                                <div class="col-xl-3 col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body py-2">
                                            <p class="mb-1 text-muted small">{{ __('firefly.total_deductible') }}</p>
                                            <h5 class="mb-0 text-danger" x-text="summary.total_deductible || '—'"></h5>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-md-6" x-show="profile.attributes && profile.attributes.tax_rate > 0">
                                    <div class="card bg-light">
                                        <div class="card-body py-2">
                                            <p class="mb-1 text-muted small">{{ __('firefly.estimated_tax') }}</p>
                                            <h5 class="mb-0 text-warning" x-text="summary.estimated_tax || '—'"></h5>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- By-category table --}}
                            <div class="row mb-3" x-show="summary.by_category && summary.by_category.length > 0">
                                <div class="col-xl-6">
                                    <h6 class="text-muted mb-2">{{ __('firefly.by_category') }}</h6>
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>{{ __('firefly.category') }}</th>
                                                <th class="text-end">{{ __('firefly.amount') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="cat in summary.by_category" :key="cat.category">
                                                <tr>
                                                    <td>
                                                        <span x-show="cat.category" x-text="cat.category"></span>
                                                        <span x-show="!cat.category" class="text-muted">
                                                            {{ __('firefly.no_category') }}
                                                        </span>
                                                    </td>
                                                    <td class="text-end text-danger" x-text="cat.total"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>

                                {{-- By-period chart --}}
                                <div class="col-xl-6" x-show="summary.by_period && summary.by_period.length > 0">
                                    <h6 class="text-muted mb-2">{{ __('firefly.by_period') }}</h6>
                                    <canvas id="periodChart"></canvas>
                                </div>
                            </div>

                            <p x-show="!isLoadingSummary && !summary.total_deductible" class="text-muted">
                                {{ __('firefly.select_dates_for_summary') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Card C: Export --}}
            <div class="row mb-3">
                <div class="col-xl-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">{{ __('firefly.export_data_menu') }}</h3>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-3">{{ __('firefly.tax_export_description') }}</p>
                            <a :href="exportUrl()" class="btn btn-default btn-sm"
                               :class="{ disabled: !dateRange.start || !dateRange.end }">
                                <i class="fa-solid fa-download me-1"></i>
                                {{ __('firefly.export_data_menu') }} (CSV)
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection
@section('scripts')
    @vite(['src/pages/extensions/tax/show.js'])
@endsection
