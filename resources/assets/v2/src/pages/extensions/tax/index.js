/*
 * index.js — Tax Profiles list page
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

let index = function () {
    return {
        notifications: {
            error:   {show: false, text: '', url: ''},
            success: {show: false, text: '', url: ''},
            wait:    {show: false, text: ''},
        },

        profiles:    [],
        isLoading:   true,
        isSubmitting: false,
        showModal:   false,

        form: {
            name:     '',
            tax_year: new Date().getFullYear(),
            tax_rate: '',
            notes:    '',
        },

        formErrors: {
            name:     [],
            tax_year: [],
            tax_rate: [],
        },

        async loadProfiles() {
            this.isLoading = true;
            try {
                const response = await api.get('/api/v1/ext/tax/profiles');
                this.profiles  = response.data.data ?? [];
            } catch (error) {
                this.notifications.error.show = true;
                this.notifications.error.text = error.response?.data?.message ?? 'Could not load profiles.';
            } finally {
                this.isLoading = false;
            }
        },

        async createProfile() {
            this.formErrors = {name: [], tax_year: [], tax_rate: []};
            this.isSubmitting = true;

            const payload = {
                name:     this.form.name,
                tax_year: parseInt(this.form.tax_year, 10),
            };
            if (this.form.tax_rate !== '') {
                payload.tax_rate = parseFloat(this.form.tax_rate);
            }
            if (this.form.notes !== '') {
                payload.notes = this.form.notes;
            }

            try {
                await api.post('/api/v1/ext/tax/profiles', payload);

                this.showModal = false;
                this.form      = {name: '', tax_year: new Date().getFullYear(), tax_rate: '', notes: ''};
                this.notifications.success.show = true;
                this.notifications.success.text = 'Tax profile created.';

                await this.loadProfiles();
            } catch (error) {
                if (error.response?.status === 422) {
                    const errors      = error.response.data.errors ?? {};
                    this.formErrors.name     = errors.name ?? [];
                    this.formErrors.tax_year = errors.tax_year ?? [];
                    this.formErrors.tax_rate = errors.tax_rate ?? [];
                } else {
                    this.notifications.error.show = true;
                    this.notifications.error.text = error.response?.data?.message ?? 'Could not create profile.';
                }
            } finally {
                this.isSubmitting = false;
            }
        },

        async deleteProfile(profileId) {
            if (!confirm('Delete this tax profile?')) {
                return;
            }
            try {
                await api.delete('/api/v1/ext/tax/profiles/' + profileId);
                this.profiles = this.profiles.filter(p => p.id !== profileId);
                this.notifications.success.show = true;
                this.notifications.success.text = 'Tax profile deleted.';
            } catch (error) {
                this.notifications.error.show = true;
                this.notifications.error.text = error.response?.data?.message ?? 'Could not delete profile.';
            }
        },

        init() {
            this.loadProfiles();
        },
    };
};

document.addEventListener('firefly-iii-bootstrapped', () => {
    Alpine.data('index', () => index());
    Alpine.start();
});
