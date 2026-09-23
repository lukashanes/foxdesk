/**
 * FoxDesk app API client.
 *
 * Small framework-free bridge for the PHP app shell, future Next shell, and
 * native clients during migration. It only talks to stable app-* API contracts.
 */
(function(window) {
    'use strict';

    var DEFAULT_TIMEOUT_MS = 15000;

    function appConfig() {
        return window.appConfig || {};
    }

    function apiBaseUrl() {
        return appConfig().apiUrl || 'index.php?page=api';
    }

    function csrfToken() {
        return window.csrfToken || appConfig().csrfToken || '';
    }

    function buildUrl(action, params) {
        var url = new URL(apiBaseUrl(), window.location.href);
        url.searchParams.set('action', action);
        params = params || {};
        Object.keys(params).forEach(function(key) {
            var value = params[key];
            if (value === undefined || value === null || value === '') return;
            if (Array.isArray(value)) {
                value.forEach(function(item) {
                    if (item !== undefined && item !== null && item !== '') {
                        url.searchParams.append(key, item);
                    }
                });
                return;
            }
            url.searchParams.set(key, value);
        });
        return url.toString();
    }

    function normalizePayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return { data: {}, meta: { legacy: true }, errors: [] };
        }
        if (payload.data && payload.meta && Array.isArray(payload.errors)) {
            return payload;
        }
        return {
            data: payload,
            meta: { legacy: true },
            errors: payload.error ? [payload.error] : [],
        };
    }

    function apiError(message, payload, status) {
        var error = new Error(message || 'API request failed.');
        error.name = 'FoxDeskApiError';
        error.payload = payload || null;
        error.status = status || 0;
        return error;
    }

    function request(action, options) {
        options = options || {};
        var method = (options.method || 'GET').toUpperCase();
        var params = options.params || {};
        var headers = {
            Accept: 'application/json',
        };
        var controller = new AbortController();
        var timeout = window.setTimeout(function() {
            controller.abort();
        }, options.timeout || DEFAULT_TIMEOUT_MS);

        var fetchOptions = {
            method: method,
            credentials: 'same-origin',
            headers: headers,
            signal: controller.signal,
        };

        if (method !== 'GET') {
            headers['X-CSRF-Token'] = csrfToken();
            if (options.body instanceof FormData) {
                fetchOptions.body = options.body;
            } else {
                headers['Content-Type'] = 'application/json';
                fetchOptions.body = JSON.stringify(options.body || {});
            }
        }

        return window.fetch(buildUrl(action, params), fetchOptions)
            .then(function(response) {
                return response.text().then(function(text) {
                    var payload = {};
                    if (text) {
                        try {
                            payload = JSON.parse(text);
                        } catch (parseError) {
                            throw apiError('API returned invalid JSON.', { raw: text }, response.status);
                        }
                    }

                    if (!response.ok || payload.success === false) {
                        throw apiError(payload.error || response.statusText, payload, response.status);
                    }

                    return normalizePayload(payload);
                });
            })
            .finally(function() {
                window.clearTimeout(timeout);
            });
    }

    function readCurrentTicketListParams() {
        var params = {};
        var source = new URLSearchParams(window.location.search);
        [
            'work_view',
            'view',
            'search',
            'sort',
            'status_id',
            'status',
            'priority_id',
            'priority',
            'organization_id',
            'organization',
            'assigned_to',
            'source',
            'tags',
            'tag',
            'due_date_filter',
            'view_mode',
            'archived',
            'limit',
            'offset',
        ].forEach(function(key) {
            if (source.has(key)) params[key] = source.get(key);
        });
        if (source.has('status') && !params.status_id) params.status_id = source.get('status');
        if (source.has('priority') && !params.priority_id) params.priority_id = source.get('priority');
        if (source.has('organization') && !params.organization_id) params.organization_id = source.get('organization');
        if (source.has('due_date') && !params.due_date_filter) params.due_date_filter = source.get('due_date');
        if (source.get('archived') === '1' && !params.view && !params.work_view) params.view = 'archived';
        return params;
    }

    window.FoxDeskApi = {
        request: request,
        buildUrl: buildUrl,
        currentTicketListParams: readCurrentTicketListParams,
        getAppShell: function(params) {
            return request('app-shell', { params: params || {} });
        },
        getAppHome: function(params) {
            return request('app-home', { params: params || {} });
        },
        getTicketList: function(params) {
            return request('app-ticket-list', { params: params || readCurrentTicketListParams() });
        },
        getTicketActions: function(params) {
            return request('app-ticket-actions', { params: params || {} });
        },
        getClientOverview: function(params) {
            return request('app-client-overview', { params: params || {} });
        },
        getReportingReview: function(params) {
            return request('app-reporting-review', { params: params || {} });
        },
        getNotificationsSummary: function(params) {
            return request('app-notifications-summary', { params: params || {} });
        },
    };
})(window);
