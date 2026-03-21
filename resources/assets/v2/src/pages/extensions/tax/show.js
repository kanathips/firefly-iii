/*
 * show.js — Tax Profile detail page
 * Copyright (c) 2024 james@firefly-iii.org.
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see https://www.gnu.org/licenses/.
 */

import '../../../boot/bootstrap.js';
import {api} from '../../../boot/axios.js';
import {Chart, BarElement, CategoryScale, LinearScale, Tooltip, Legend} from 'chart.js';
import {getDefaultChartSettings} from '../../../support/default-chart-settings.js';

Chart.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend);

let periodChart = null;

let show = function () {
    return {
        notifications: {
            error:   {show: false, text: '', url: ''},
            success: {show: false, text: '', url: ''},
            wait:    {show: false, text: ''},
        },

        profileId:        0,
        profile:          {},
        tags:             [],
        isLoadingTags:    false,
        isLoadingSummary: false,

        summary: {
            total_deductible: null,
            estimated_tax:    null,
            by_category:      [],
            by_period:        [],
        },

        dateRange: {
            start: new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10),
            end:   new Date(new Date().getFullYear(), 11, 31).toISOString().slice(0, 10),
        },

        period: 'month',

        tagSearch:      '',
        tagSuggestions: [],
        selectedTagId:  null,

        async loadProfile() {
            try {
                const response = await api.get('/api/v1/ext/tax/profiles');
                const all      = response.data.data ?? [];
                this.profile   = all.find(p => p.id === this.profileId) ?? {};
            } catch (error) {
                this.notifications.error.show = true;
                this.notifications.error.text = error.response?.data?.message ?? 'Could not load profile.';
            }
        },

        async loadLinkedTags() {
            this.isLoadingTags = true;
            try {
                const response = await api.get('/api/v1/ext/tax/profiles/' + this.profileId + '/tags');
                this.tags      = response.data.data ?? [];
            } catch (error) {
                this.notifications.error.show = true;
                this.notifications.error.text = error.response?.data?.message ?? 'Could not load tags.';
            } finally {
                this.isLoadingTags = false;
            }
        },

        async loadSummary() {
            if (!this.dateRange.start || !this.dateRange.end) {
                return;
            }
            this.isLoadingSummary = true;
            try {
                const response = await api.get(
                    '/api/v1/ext/tax/profiles/' + this.profileId + '/summary',
                    {params: {start: this.dateRange.start, end: this.dateRange.end, period: this.period}}
                );
                const data = response.data.data ?? {};

                this.summary.total_deductible = data.total_deductible ?? null;

                // by_category is an object { "CategoryName": float } — convert to array
                this.summary.by_category = Object.entries(data.by_category ?? {}).map(
                    ([category, total]) => ({category, total})
                );

                // by_period is an object { "2024-01": { total: float, journals: [] } } — convert to array
                this.summary.by_period = Object.entries(data.by_period ?? {}).map(
                    ([period, v]) => ({period, total: v.total})
                );

                // Estimated tax
                const taxRate = this.profile.attributes?.tax_rate ?? 0;
                if (taxRate > 0 && data.total_deductible) {
                    this.summary.estimated_tax = (data.total_deductible * taxRate / 100).toFixed(2);
                } else {
                    this.summary.estimated_tax = null;
                }

                this.renderChart();
            } catch (error) {
                this.notifications.error.show = true;
                this.notifications.error.text = error.response?.data?.message ?? 'Could not load summary.';
            } finally {
                this.isLoadingSummary = false;
            }
        },

        renderChart() {
            if (!this.summary.by_period || this.summary.by_period.length === 0) {
                return;
            }

            const canvas = document.getElementById('periodChart');
            if (!canvas) {
                return;
            }

            if (periodChart) {
                periodChart.destroy();
            }

            const labels  = this.summary.by_period.map(p => p.period);
            const amounts = this.summary.by_period.map(p => p.total);

            const settings         = getDefaultChartSettings('column');
            settings.data.labels   = labels;
            settings.data.datasets = [{
                label:           'Deductible',
                data:            amounts,
                backgroundColor: 'rgba(220, 53, 69, 0.6)',
                borderColor:     'rgba(220, 53, 69, 1)',
                borderWidth:     1,
            }];

            periodChart = new Chart(canvas, settings);
        },

        async searchTags() {
            if (this.tagSearch.length < 1) {
                this.tagSuggestions = [];
                return;
            }
            try {
                const response      = await api.get('/api/v1/autocomplete/tags', {params: {query: this.tagSearch}});
                this.tagSuggestions = response.data ?? [];
            } catch (_) {
                this.tagSuggestions = [];
            }
        },

        selectTag(suggestion) {
            this.selectedTagId  = suggestion.id;
            this.tagSearch      = suggestion.name ?? suggestion.tag ?? '';
            this.tagSuggestions = [];
        },

        async addTagById() {
            if (!this.selectedTagId) {
                return;
            }

            try {
                await api.post('/api/v1/ext/tax/profiles/' + this.profileId + '/tags', {tag_id: this.selectedTagId});
                this.notifications.success.show = true;
                this.notifications.success.text = 'Tag linked.';
                this.tagSearch      = '';
                this.selectedTagId  = null;
                this.tagSuggestions = [];
                await this.loadLinkedTags();
            } catch (error) {
                this.notifications.error.show = true;
                this.notifications.error.text = error.response?.data?.message ?? 'Could not link tag.';
            }
        },

        async removeTag(tagId) {
            try {
                await api.delete('/api/v1/ext/tax/profiles/' + this.profileId + '/tags/' + tagId);
                this.tags = this.tags.filter(t => t.id !== tagId);
                this.notifications.success.show = true;
                this.notifications.success.text = 'Tag removed.';
            } catch (error) {
                this.notifications.error.show = true;
                this.notifications.error.text = error.response?.data?.message ?? 'Could not remove tag.';
            }
        },

        exportUrl() {
            if (!this.dateRange.start || !this.dateRange.end) {
                return '#';
            }
            return '/api/v1/ext/tax/profiles/' + this.profileId
                + '/export?start=' + this.dateRange.start
                + '&end=' + this.dateRange.end;
        },

        init(profileId) {
            this.profileId = profileId;
            this.loadProfile();
            this.loadLinkedTags();
        },
    };
};

document.addEventListener('firefly-iii-bootstrapped', () => {
    Alpine.data('show', () => show());
    Alpine.start();
});
