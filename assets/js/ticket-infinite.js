/**
 * Ticket list: infinite loading.
 *
 * Progressive enhancement over the server-rendered pagination. When the page
 * has a "Next" link, the pagination bar is hidden, a sentinel is watched with
 * IntersectionObserver, and the next page is fetched and parsed. Its desktop
 * rows and mobile cards are appended to the existing containers; the sentinel
 * then follows the next page's "Next" link until none is left.
 */
(function() {
    'use strict';

    function init() {
        var page = document.querySelector('[data-ticket-registry-surface]');
        var nav = document.querySelector('nav.registry-pagination');
        var sentinel = document.querySelector('[data-ticket-infinite-sentinel]');
        if (!page || !nav || !sentinel || !('IntersectionObserver' in window) || !('DOMParser' in window)) {
            return;
        }
        var next = nav.querySelector('[data-registry-next]');
        if (!next || !next.getAttribute('href')) {
            return;
        }

        var nextHref = next.getAttribute('href');
        var loading = false;
        var finished = false;

        page.classList.add('is-infinite');
        sentinel.hidden = false;

        function desktopTarget(row) {
            if (row.closest('#closed-tickets-desktop')) {
                var closed = document.getElementById('closed-tickets-desktop');
                if (closed) return closed;
            }
            var table = document.querySelector('#filter-form .tickets-table');
            if (!table) return null;
            var bodies = Array.prototype.filter.call(table.tBodies, function(body) {
                return !body.classList.contains('ticket-closed-group') && body.id !== 'closed-tickets-desktop';
            });
            return bodies.length ? bodies[bodies.length - 1] : table.tBodies[0] || null;
        }

        function mobileTarget(card) {
            if (card.closest('#closed-tickets-mobile')) {
                var closed = document.getElementById('closed-tickets-mobile');
                if (closed) return closed;
            }
            var list = page.querySelector('.ticket-registry-card .lg\\:hidden');
            if (!list) return null;
            var groups = Array.prototype.filter.call(list.querySelectorAll(':scope > .divide-y'), function(group) {
                return group.id !== 'closed-tickets-mobile';
            });
            return groups.length ? groups[groups.length - 1] : list;
        }

        function alreadyPresent(selector, id) {
            return !!document.querySelector(selector + '[data-ticket-id="' + id + '"]');
        }

        function appendFrom(doc) {
            var added = [];
            Array.prototype.forEach.call(doc.querySelectorAll('tr.ticket-row[data-ticket-id]'), function(row) {
                var id = row.getAttribute('data-ticket-id');
                if (!id || alreadyPresent('tr.ticket-row', id)) return;
                var target = desktopTarget(row);
                if (!target) return;
                var node = document.importNode(row, true);
                target.appendChild(node);
                added.push(node);
            });
            Array.prototype.forEach.call(doc.querySelectorAll('.ticket-list-item[data-ticket-id]'), function(card) {
                var id = card.getAttribute('data-ticket-id');
                if (!id || alreadyPresent('.ticket-list-item', id)) return;
                var target = mobileTarget(card);
                if (!target) return;
                var node = document.importNode(card, true);
                target.appendChild(node);
                added.push(node);
            });
            if (added.length) {
                document.dispatchEvent(new CustomEvent('ticketlist:appended', { detail: { rows: added } }));
            }
            return added.length;
        }

        function finish(failed) {
            finished = true;
            observer.disconnect();
            sentinel.hidden = true;
            sentinel.classList.remove('is-loading');
            if (failed) {
                // Hand control back to the server-rendered pagination.
                page.classList.remove('is-infinite');
            }
        }

        function load() {
            if (loading || finished || !nextHref) return;
            loading = true;
            sentinel.classList.add('is-loading');
            sentinel.setAttribute('aria-busy', 'true');
            var requested = nextHref;
            fetch(requested, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            }).then(function(html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                appendFrom(doc);
                var following = doc.querySelector('nav.registry-pagination [data-registry-next]');
                var followingHref = following ? following.getAttribute('href') : '';
                nextHref = followingHref && followingHref !== requested ? followingHref : '';
                if (!nextHref) {
                    finish(false);
                }
            }).catch(function() {
                finish(true);
            }).then(function() {
                loading = false;
                sentinel.classList.remove('is-loading');
                sentinel.removeAttribute('aria-busy');
                if (!finished && sentinelVisible()) {
                    load();
                }
            });
        }

        function sentinelVisible() {
            var rect = sentinel.getBoundingClientRect();
            return rect.top < (window.innerHeight || document.documentElement.clientHeight) + 400;
        }

        var observer = new IntersectionObserver(function(entries) {
            if (entries.some(function(entry) { return entry.isIntersecting; })) {
                load();
            }
        }, { rootMargin: '400px 0px' });
        observer.observe(sentinel);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
