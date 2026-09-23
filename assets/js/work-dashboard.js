(function () {
    'use strict';

    function normalise(value) {
        return String(value || '').toLowerCase().trim();
    }

    function setHidden(element, hidden) {
        if (!element) return;
        element.classList.toggle('is-hidden', !!hidden);
        element.hidden = !!hidden;
    }

    function initSurface(surface) {
        var mode = surface.getAttribute('data-work-filter-mode') || '';
        var input = surface.querySelector('[data-work-log-search]');
        var prompt = surface.querySelector('[data-work-search-prompt]');
        var empty = surface.querySelector('[data-work-search-empty]');
        var items = Array.prototype.slice.call(surface.querySelectorAll('[data-work-activity-item]'));
        var rows = Array.prototype.slice.call(surface.querySelectorAll('[data-work-team-row]'));
        var storageKey = surface.hasAttribute('data-work-team-time')
            ? 'foxdesk_work_team_search'
            : 'foxdesk_work_my_search';

        function itemMatches(item, query) {
            return normalise(item.getAttribute('data-work-search-text') || item.textContent).indexOf(query) !== -1;
        }

        function rowMatches(row, query) {
            var rowText = normalise(row.getAttribute('data-work-search-text') || row.textContent);
            var nestedItems = Array.prototype.slice.call(row.querySelectorAll('[data-work-activity-item]'));
            var nestedVisible = 0;

            nestedItems.forEach(function (item) {
                var match = itemMatches(item, query);
                setHidden(item, !match);
                if (match) nestedVisible += 1;
            });

            return rowText.indexOf(query) !== -1 || nestedVisible > 0;
        }

        function applySearch() {
            if (mode !== 'search') {
                setHidden(prompt, true);
                setHidden(empty, true);
                return;
            }

            var query = normalise(input ? input.value : '');
            var hasQuery = query.length > 0;
            var visible = 0;

            setHidden(prompt, hasQuery);

            items.forEach(function (item) {
                var match = hasQuery && itemMatches(item, query);
                setHidden(item, !match);
                if (match) visible += 1;
            });

            rows.forEach(function (row) {
                var match = hasQuery && rowMatches(row, query);
                setHidden(row, !match);
                if (match) visible += 1;
            });

            setHidden(empty, !hasQuery || visible > 0);
        }

        if (!input) {
            applySearch();
            return;
        }

        try {
            input.value = localStorage.getItem(storageKey) || '';
        } catch (_) {}

        input.addEventListener('compositionend', applySearch);
        input.addEventListener('input', function (event) {
            if (event.isComposing) return;
            try {
                localStorage.setItem(storageKey, input.value || '');
            } catch (_) {}
            applySearch();
        });

        applySearch();
    }

    function cssVar(name, fallback) {
        var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return value || fallback;
    }

    function formatMinutes(minutes) {
        var total = Math.max(0, Math.round(Number(minutes) || 0));
        var hours = Math.floor(total / 60);
        var mins = total % 60;
        if (hours > 0) return hours + 'h ' + mins + 'min';
        return mins + ' min';
    }

    function formatTimerSeconds(seconds) {
        var total = Math.max(0, Math.floor(Number(seconds) || 0));
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var remainder = total % 60;

        if (hours > 0) {
            return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(remainder).padStart(2, '0');
        }

        return minutes + ':' + String(remainder).padStart(2, '0');
    }

    function timerElapsedSeconds(timer, nowSeconds) {
        var elapsed = Number(timer && timer.elapsed_seconds);
        if (timer && timer.is_paused && Number.isFinite(elapsed)) {
            return Math.max(0, Math.floor(elapsed));
        }

        var started = Number(timer && timer.started_at);
        var paused = Number(timer && timer.paused_seconds) || 0;
        if (started > 0) {
            return Math.max(0, Math.floor(nowSeconds - started - paused));
        }

        return Math.max(0, Math.floor(elapsed || 0));
    }

    function appendBidiText(parent, value) {
        var bdi = document.createElement('bdi');
        bdi.dir = 'auto';
        bdi.textContent = String(value || '');
        parent.appendChild(bdi);
    }

    function initActiveTimerSync() {
        var panel = document.querySelector('[data-work-current]');
        var totalNode = document.querySelector('[data-work-active-minutes]');
        if (!panel) return;

        var content = panel.querySelector('[data-work-current-content]');
        var action = panel.querySelector('[data-work-current-action]');
        var actionLabel = panel.querySelector('[data-work-current-action-label]');
        var announcement = panel.querySelector('[data-work-current-announcement]');
        var timers = [];
        var hasLiveTimerState = false;
        var lastAnnouncementKey = '';
        var labels = {
            running: panel.getAttribute('data-running-label') || 'Running',
            paused: panel.getAttribute('data-paused-label') || 'Paused',
            empty: panel.getAttribute('data-empty-label') || 'No active work right now.',
            viewTicket: panel.getAttribute('data-view-ticket-label') || 'View ticket',
            viewTickets: panel.getAttribute('data-view-tickets-label') || 'Tickets',
            ticket: panel.getAttribute('data-ticket-label') || 'Ticket',
            activeTimers: panel.getAttribute('data-active-timers-label') || 'Active Timers'
        };
        var ticketsUrl = panel.getAttribute('data-tickets-url') || 'index.php?page=tickets';

        function updateTotal() {
            if (!hasLiveTimerState || !totalNode) return;
            var nowSeconds = Math.floor(Date.now() / 1000);
            var seconds = timers.reduce(function (total, timer) {
                return total + timerElapsedSeconds(timer, nowSeconds);
            }, 0);
            totalNode.textContent = formatMinutes(Math.floor(seconds / 60));
        }

        function updateAnnouncement(timer, timerCount) {
            var key = timer
                ? [timer.ticket_id || '', timer.is_paused ? 'paused' : 'running', timerCount].join(':')
                : 'empty';
            if (!announcement || key === lastAnnouncementKey) return;

            lastAnnouncementKey = key;
            announcement.textContent = timer
                ? (timer.is_paused ? labels.paused : labels.running) + ': ' + (timer.ticket_title || labels.ticket)
                : labels.empty;
        }

        function renderTimers(nextTimers) {
            timers = Array.isArray(nextTimers) ? nextTimers : [];
            hasLiveTimerState = true;
            var primary = timers[0] || null;
            var nowSeconds = Math.floor(Date.now() / 1000);

            if (content) {
                while (content.firstChild) content.removeChild(content.firstChild);

                if (primary) {
                    var elapsed = timerElapsedSeconds(primary, nowSeconds);
                    var time = document.createElement('div');
                    time.className = 'work-active-panel__time';
                    time.textContent = formatTimerSeconds(elapsed);
                    if (!primary.is_paused && Number(primary.started_at) > 0) {
                        time.classList.add('timer-display');
                        time.dataset.started = String(primary.started_at);
                        time.dataset.pausedSeconds = String(Number(primary.paused_seconds) || 0);
                        time.dataset.timerDisplaySeconds = '1';
                    }
                    content.appendChild(time);

                    var controls = document.createElement('div');
                    controls.className = 'work-timer-controls';
                    var toggle = document.createElement('button');
                    toggle.type = 'button';
                    toggle.className = 'btn btn-secondary btn-sm';
                    toggle.textContent = panel.dataset[primary.is_paused ? 'resumeLabel' : 'pauseLabel'];
                    toggle.addEventListener('click', function () { window.sidebarToggleTimer(primary.ticket_id, !!primary.is_paused); });
                    var stop = document.createElement('button');
                    stop.type = 'button';
                    stop.className = 'btn btn-secondary btn-sm';
                    stop.textContent = panel.dataset.stopLabel;
                    stop.addEventListener('click', function () { window.sidebarStopTimer(primary.ticket_id); });
                    controls.append(toggle, stop);
                    content.appendChild(controls);

                    var title = document.createElement('strong');
                    title.className = 'work-active-panel__title';
                    appendBidiText(title, primary.ticket_title || labels.ticket);
                    content.appendChild(title);

                    var meta = document.createElement('div');
                    meta.className = 'work-active-panel__meta';

                    var ticket = document.createElement('span');
                    ticket.className = 'work-active-panel__ticket';
                    ticket.textContent = primary.ticket_code || ('#' + String(primary.ticket_id || ''));
                    meta.appendChild(ticket);

                    var state = document.createElement('span');
                    state.className = 'work-active-panel__state';
                    state.textContent = primary.is_paused ? labels.paused : labels.running;
                    meta.appendChild(state);

                    if (timers.length > 1) {
                        var count = document.createElement('span');
                        count.className = 'work-active-panel__count';
                        count.title = labels.activeTimers;
                        count.textContent = '+' + String(timers.length - 1);
                        meta.appendChild(count);
                    }
                    content.appendChild(meta);
                } else {
                    var empty = document.createElement('div');
                    empty.className = 'work-active-panel__empty';
                    var emptyText = document.createElement('span');
                    emptyText.textContent = labels.empty;
                    empty.appendChild(emptyText);
                    content.appendChild(empty);
                }
            }

            if (action) {
                action.setAttribute('href', primary && primary.ticket_url ? String(primary.ticket_url) : ticketsUrl);
            }
            if (actionLabel) actionLabel.textContent = primary ? labels.viewTicket : labels.viewTickets;

            updateTotal();
            updateAnnouncement(primary, timers.length);
        }

        document.addEventListener('activeTimersUpdated', function (event) {
            renderTimers(event.detail && event.detail.timers ? event.detail.timers : []);
        });

        setInterval(updateTotal, 1000);
    }

    function initHoursChart() {
        var surface = document.querySelector('[data-work-hours-chart]');
        var canvas = surface ? surface.querySelector('[data-work-hours-chart-canvas]') : null;
        var payloadNode = surface ? surface.querySelector('[data-work-hours-chart-payload]') : null;
        var fallback = surface ? surface.querySelector('[data-work-hours-chart-fallback]') : null;

        function showFallback() {
            if (!surface) return;
            surface.classList.remove('is-chart-ready');
            surface.classList.add('is-chart-fallback');
            if (fallback) fallback.hidden = false;
        }

        function showCanvas() {
            if (!surface) return;
            surface.classList.add('is-chart-ready');
            surface.classList.remove('is-chart-fallback');
            if (fallback) fallback.hidden = true;
        }

        if (!surface || !canvas || !payloadNode || typeof window.Chart === 'undefined') {
            showFallback();
            return;
        }

        var payload;
        try {
            payload = JSON.parse(payloadNode.textContent || '{}');
        } catch (_) {
            showFallback();
            return;
        }

        var labels = Array.isArray(payload.labels) ? payload.labels : [];
        var datasets = Array.isArray(payload.datasets) ? payload.datasets : [];
        var fullLabels = Array.isArray(payload.fullLabels) ? payload.fullLabels : labels;
        var textColor = cssVar('--text-secondary', '#475569');
        var mutedColor = cssVar('--text-muted', '#64748b');
        var gridColor = cssVar('--border-light', 'rgba(148, 163, 184, 0.28)');
        var tooltipBg = cssVar('--surface-primary', '#ffffff');
        var tooltipBorder = cssVar('--border-color', 'rgba(148, 163, 184, 0.35)');
        var denseChart = labels.length > 21;

        var sourceDatasets = datasets
            .filter(function (dataset) {
                return Array.isArray(dataset.data) && dataset.data.some(function (value) { return Number(value) > 0; });
            });

        function hasPositiveValue(dataset, dataIndex) {
            return Number(dataset && dataset.data ? dataset.data[dataIndex] : 0) > 0;
        }

        function stackEdgeRadius(datasetIndex, dataIndex) {
            var radius = denseChart ? 5 : 7;
            var topIndex = -1;

            for (var index = sourceDatasets.length - 1; index >= 0; index -= 1) {
                if (hasPositiveValue(sourceDatasets[index], dataIndex)) {
                    topIndex = index;
                    break;
                }
            }

            if (datasetIndex !== topIndex) {
                return 0;
            }

            return {
                topLeft: radius,
                topRight: radius,
                bottomLeft: 0,
                bottomRight: 0
            };
        }

        var chartDatasets = sourceDatasets
            .map(function (dataset, datasetIndex) {
                return {
                    label: dataset.label || 'Agent',
                    data: dataset.data || [],
                    backgroundColor: dataset.backgroundColor || '#3b5bdb',
                    borderColor: dataset.borderColor || dataset.backgroundColor || '#3b5bdb',
                    borderWidth: 0,
                    borderRadius: function (context) {
                        return stackEdgeRadius(datasetIndex, context.dataIndex);
                    },
                    borderSkipped: false,
                    barPercentage: denseChart ? 0.86 : 0.78,
                    categoryPercentage: denseChart ? 0.82 : 0.74,
                    maxBarThickness: denseChart ? 26 : 34
                };
            });

        if (!labels.length || !chartDatasets.length) {
            showFallback();
            return;
        }

        if (surface._foxdeskWorkChart) {
            surface._foxdeskWorkChart.destroy();
        }

        surface._foxdeskWorkChart = new window.Chart(canvas.getContext('2d'), {
            type: 'bar',
            plugins: [{
                id: 'foxdeskSavedTimeLabels',
                afterDatasetsDraw: function (chart) {
                    var context = chart.ctx;
                    context.save();
                    context.font = '500 12px Inter, sans-serif';
                    context.fillStyle = textColor;
                    context.textAlign = 'center';
                    context.textBaseline = 'bottom';
                    var previousRight = -Infinity;
                    labels.forEach(function (_, index) {
                        var total = 0;
                        var top = chart.chartArea.bottom;
                        var x = 0;
                        chart.data.datasets.forEach(function (dataset, datasetIndex) {
                            if (!chart.isDatasetVisible(datasetIndex)) return;
                            var value = Number(dataset.data[index]) || 0;
                            var bar = chart.getDatasetMeta(datasetIndex).data[index];
                            if (bar && value > 0) { total += value; top = Math.min(top, bar.y); x = bar.x; }
                        });
                        if (!total) return;
                        var label = formatMinutes(total);
                        var half = context.measureText(label).width / 2;
                        // Dense date ranges retain exact values in the tooltip without overlapping labels.
                        if (x - half < previousRight + 6) return;
                        context.fillText(label, x, Math.max(chart.chartArea.top + 12, top - 8));
                        previousRight = x + half;
                    });
                    context.restore();
                }
            }],
            data: {
                labels: labels,
                datasets: chartDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : {duration: 180},
                layout: {padding: {top: 24}},
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        align: 'start',
                        labels: {
                            color: textColor,
                            usePointStyle: true,
                            pointStyle: 'rectRounded',
                            boxWidth: 9,
                            boxHeight: 9,
                            padding: 16,
                            font: {
                                size: 12,
                                weight: '700'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: tooltipBg,
                        borderColor: tooltipBorder,
                        borderWidth: 1,
                        titleColor: cssVar('--text-primary', '#0f172a'),
                        bodyColor: textColor,
                        footerColor: cssVar('--text-primary', '#0f172a'),
                        padding: 12,
                        cornerRadius: 10,
                        displayColors: true,
                        boxWidth: 8,
                        boxHeight: 8,
                        usePointStyle: true,
                        callbacks: {
                            title: function (items) {
                                var index = items && items[0] ? items[0].dataIndex : 0;
                                return fullLabels[index] || labels[index] || '';
                            },
                            label: function (item) {
                                return item.dataset.label + ': ' + formatMinutes(item.raw);
                            },
                            footer: function (items) {
                                var total = (items || []).reduce(function (sum, item) {
                                    return sum + (Number(item.raw) || 0);
                                }, 0);
                                return (payload.totalLabel || 'Total') + ': ' + formatMinutes(total);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: mutedColor,
                            maxRotation: 0,
                            autoSkip: true,
                            autoSkipPadding: denseChart ? 18 : 14,
                            maxTicksLimit: denseChart ? 10 : 14,
                            font: {
                                size: 11,
                                weight: '700'
                            }
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grace: '15%',
                        border: {
                            display: false
                        },
                        grid: {
                            color: gridColor
                        },
                        ticks: {
                            color: mutedColor,
                            precision: 0,
                            callback: function (value) {
                                return formatMinutes(value);
                            },
                            font: {
                                size: 11,
                                weight: '700'
                            }
                        }
                    }
                }
            }
        });
        showCanvas();
        var selectedDay = 0;
        canvas.addEventListener('keydown', function (event) {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            selectedDay = event.key === 'Home' ? 0 : event.key === 'End' ? labels.length - 1 : Math.max(0, Math.min(labels.length - 1, selectedDay + (event.key === 'ArrowRight' ? 1 : -1)));
            var chart = surface._foxdeskWorkChart;
            var elements = chart.data.datasets.map(function (_, index) { return {datasetIndex: index, index: selectedDay}; });
            chart.setActiveElements(elements);
            chart.tooltip.setActiveElements(elements, {x: chart.scales.x.getPixelForValue(selectedDay), y: chart.chartArea.top});
            chart.update('none');
            var announcement = surface.querySelector('[data-work-chart-keyboard-value]');
            if (announcement) announcement.textContent = fullLabels[selectedDay] + ': ' + chart.data.datasets.map(function (dataset) { return dataset.label + ' ' + formatMinutes(dataset.data[selectedDay]); }).join(', ');
        });
    }

    function init() {
        var chartViewButtons = Array.from(document.querySelectorAll('[data-work-chart-view]'));
        function selectChartView(view) {
            chartViewButtons.forEach(function (button) { var selected = button.dataset.workChartView === view; button.classList.toggle('is-active', selected); button.setAttribute('aria-pressed', String(selected)); });
            var chartSurface = document.querySelector('[data-work-hours-chart]');
            var matrix = document.querySelector('[data-work-client-matrix]');
            if (chartSurface) chartSurface.hidden = view === 'table';
            if (matrix) matrix.hidden = view !== 'table';
        }
        chartViewButtons.forEach(function (button) { button.addEventListener('click', function () { selectChartView(button.dataset.workChartView); }); });
        selectChartView(new URLSearchParams(window.location.search).get('chart_view') === 'table' ? 'table' : 'chart');
        var contextTabs = Array.from(document.querySelectorAll('[data-work-context-tab]'));
        function selectContext(tab) {
            contextTabs.forEach(function (item) {
                var selected = item === tab;
                item.classList.toggle('is-active', selected);
                item.setAttribute('aria-selected', String(selected));
                item.tabIndex = selected ? 0 : -1;
            });
            document.querySelectorAll('[data-work-context-panel]').forEach(function (panel) { panel.hidden = panel.dataset.workContextPanel !== tab.dataset.workContextTab; });
        }
        contextTabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { selectContext(tab); });
            tab.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft' && event.key !== 'Home' && event.key !== 'End') return;
                event.preventDefault();
                var next = event.key === 'Home' ? 0 : event.key === 'End' ? contextTabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + contextTabs.length) % contextTabs.length;
                selectContext(contextTabs[next]); contextTabs[next].focus();
            });
        });
        document.querySelectorAll('[data-work-context-panel]').forEach(function (panel) {
            var events = Array.from(panel.querySelectorAll('[data-work-event]'));
            if (!panel.querySelector('[data-work-event-pagination]')) return;
            var page = 0;
            function renderEvents() {
                var eligible = events.filter(function (row) { return panel.dataset.workContextPanel !== 'attention' || !row.classList.contains('is-reviewed'); });
                var pages = Math.max(1, Math.ceil(eligible.length / 3));
                page = Math.max(0, Math.min(page, pages - 1));
                events.forEach(function (row) { row.hidden = true; });
                eligible.slice(page * 3, (page + 1) * 3).forEach(function (row) { row.hidden = false; });
                panel.querySelector('[data-work-event-empty]').hidden = eligible.length > 0;
                panel.querySelector('[data-work-event-pagination]').hidden = pages <= 1;
                panel.querySelector('[data-work-event-page]').textContent = (page + 1) + ' / ' + pages;
                panel.querySelectorAll('[data-work-event-step]').forEach(function (control) { control.disabled = Number(control.dataset.workEventStep) < 0 ? page === 0 : page >= pages - 1; });
                if (panel.dataset.workContextPanel === 'attention') {
                    var badge = document.querySelector('[data-work-context-tab="attention"] span');
                    if (badge) badge.textContent = String(eligible.length);
                }
            }
            panel.querySelectorAll('[data-work-event-step]').forEach(function (button) {
                button.addEventListener('click', function () {
                    page += Number(button.dataset.workEventStep);
                    renderEvents();
                });
            });
            document.addEventListener('work-events-updated', renderEvents);
            renderEvents();
        });
        document.querySelectorAll('[data-work-client-summary]').forEach(function (summary) {
            var input = summary.querySelector('[data-work-client-search]');
            var rows = Array.from(summary.querySelectorAll('[data-work-client-row]'));
            var page = 0;
            function filterClients() {
                var query = normalise(input ? input.value : '').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                var matched = rows.filter(function (row) { return normalise(row.textContent).normalize('NFD').replace(/[\u0300-\u036f]/g, '').indexOf(query) >= 0; });
                var pages = Math.max(1, Math.ceil(matched.length / 6));
                page = Math.max(0, Math.min(page, pages - 1));
                rows.forEach(function (row) { var index = matched.indexOf(row); row.hidden = index < page * 6 || index >= (page + 1) * 6; });
                var label = summary.querySelector('[data-work-client-page]');
                if (label) label.textContent = (page + 1) + ' / ' + pages;
                summary.querySelectorAll('[data-work-client-step]').forEach(function (button) { button.disabled = Number(button.dataset.workClientStep) < 0 ? page === 0 : page >= pages - 1; });
            }
            if (input) {
                input.addEventListener('input', function (event) { if (!event.isComposing) { page = 0; filterClients(); } });
                input.addEventListener('compositionend', function () { page = 0; filterClients(); });
            }
            summary.querySelectorAll('[data-work-client-step]').forEach(function (button) { button.addEventListener('click', function () { page += Number(button.dataset.workClientStep); filterClients(); }); });
            filterClients();
        });
        document.querySelectorAll('[data-work-log-surface]').forEach(initSurface);
        initActiveTimerSync();
        initHoursChart();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
