/**
 * App shell behaviour: mobile drawer, collapsible menu groups, menu search and
 * the top-bar popovers.
 *
 * Deliberately dependency-free — footer.php loads jQuery/Bootstrap on some
 * pages but not all, and the shell has to behave the same everywhere.
 */
(function () {
    'use strict';

    var body = document.body;
    var sidebar = document.getElementById('appSidebar');
    var navSearch = document.getElementById('appNavSearch');
    var navEmpty = document.getElementById('appNavEmpty');
    var burger = document.querySelector('[data-app-nav-open]');
    var GROUPS_KEY = 'ea.shell.groups.v1';
    var isMobile = function () { return window.matchMedia('(max-width: 991.98px)').matches; };

    /* ---------------- drawer (small screens) ---------------- */
    function openNav() {
        body.classList.add('app-nav-open');
        if (burger) { burger.setAttribute('aria-expanded', 'true'); }
        var close = sidebar && sidebar.querySelector('[data-app-nav-close]');
        if (close) { close.focus(); }
    }
    function closeNav() {
        if (!body.classList.contains('app-nav-open')) { return; }
        body.classList.remove('app-nav-open');
        if (burger) { burger.setAttribute('aria-expanded', 'false'); burger.focus(); }
    }
    if (burger) {
        burger.addEventListener('click', function () {
            if (body.classList.contains('app-nav-open')) { closeNav(); } else { openNav(); }
        });
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-app-nav-close]'), function (el) {
        el.addEventListener('click', closeNav);
    });
    if (sidebar) {
        sidebar.addEventListener('click', function (event) {
            var link = event.target.closest ? event.target.closest('a.app-nav-link') : null;
            if (link && isMobile()) { closeNav(); }
        });
    }

    /* ---------------- collapsible groups ---------------- */
    var groups = Array.prototype.slice.call(document.querySelectorAll('[data-nav-group]'));
    var saved = {};
    try { saved = JSON.parse(window.localStorage.getItem(GROUPS_KEY) || '{}') || {}; } catch (e) { saved = {}; }

    function persistGroups() {
        var state = {};
        groups.forEach(function (group) {
            state[group.getAttribute('data-nav-group')] = !group.classList.contains('is-collapsed');
        });
        try { window.localStorage.setItem(GROUPS_KEY, JSON.stringify(state)); } catch (e) { /* private mode */ }
    }

    groups.forEach(function (group) {
        var slug = group.getAttribute('data-nav-group');
        var head = group.querySelector('[data-group-toggle]');
        // A page inside a folded shelf always opens it, whatever was saved.
        if (group.querySelector('.is-active')) {
            group.classList.remove('is-collapsed');
        } else if (Object.prototype.hasOwnProperty.call(saved, slug)) {
            group.classList.toggle('is-collapsed', saved[slug] === false);
        }
        if (head) {
            head.setAttribute('aria-expanded', group.classList.contains('is-collapsed') ? 'false' : 'true');
            head.addEventListener('click', function () {
                // While a search is running every group is force-opened, so a
                // toggle would only hide the results the reader is looking at.
                if (navSearch && navSearch.value.trim() !== '') { return; }
                var collapsed = group.classList.toggle('is-collapsed');
                head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                persistGroups();
            });
        }
    });

    /* ---------------- menu search ---------------- */
    function applySearch(query) {
        var needle = query.trim().toLowerCase();
        var anyVisible = false;

        groups.forEach(function (group) {
            var groupHasMatch = false;
            Array.prototype.forEach.call(group.querySelectorAll('.app-nav-item'), function (item) {
                var label = item.textContent.toLowerCase();
                var match = needle === '' || label.indexOf(needle) !== -1;
                item.hidden = !match;
                if (match) { groupHasMatch = true; }
            });
            group.hidden = !groupHasMatch;
            if (needle !== '') {
                group.classList.remove('is-collapsed');
                var head = group.querySelector('[data-group-toggle]');
                if (head) { head.setAttribute('aria-expanded', 'true'); }
            } else {
                var slug = group.getAttribute('data-nav-group');
                var open = group.querySelector('.is-active') ? true
                    : (Object.prototype.hasOwnProperty.call(saved, slug) ? saved[slug] !== false : !group.classList.contains('is-collapsed'));
                group.classList.toggle('is-collapsed', open === false);
                var toggle = group.querySelector('[data-group-toggle]');
                if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
            }
            if (groupHasMatch) { anyVisible = true; }
        });

        if (navEmpty) { navEmpty.hidden = anyVisible; }
    }

    if (navSearch) {
        navSearch.addEventListener('input', function () { applySearch(navSearch.value); });
        navSearch.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                navSearch.value = '';
                applySearch('');
                navSearch.blur();
            }
            if (event.key === 'Enter') {
                var first = document.querySelector('.app-nav-group:not([hidden]) .app-nav-link');
                if (first) { first.focus(); }
            }
        });
    }

    function focusMenuSearch() {
        if (!navSearch) { return; }
        if (isMobile()) {
            openNav();
            window.setTimeout(function () { navSearch.focus(); }, 220);
        } else {
            navSearch.focus();
            navSearch.select();
        }
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-app-search-focus]'), function (el) {
        el.addEventListener('click', focusMenuSearch);
    });

    document.addEventListener('keydown', function (event) {
        var tag = (event.target.tagName || '').toLowerCase();
        var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable;
        if ((event.key === '/' && !typing) || ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k')) {
            event.preventDefault();
            focusMenuSearch();
        }
        if (event.key === 'Escape') {
            closeNav();
            closePops(null);
        }
    });

    /* ---------------- top-bar popovers ---------------- */
    var popButtons = Array.prototype.slice.call(document.querySelectorAll('[data-app-pop]'));

    function closePops(except) {
        popButtons.forEach(function (button) {
            if (button === except) { return; }
            var panel = document.getElementById('appPop' + button.getAttribute('data-app-pop').charAt(0).toUpperCase()
                + button.getAttribute('data-app-pop').slice(1));
            if (panel) { panel.hidden = true; }
            button.setAttribute('aria-expanded', 'false');
        });
    }

    popButtons.forEach(function (button) {
        var name = button.getAttribute('data-app-pop');
        var panel = document.getElementById('appPop' + name.charAt(0).toUpperCase() + name.slice(1));
        if (!panel) { return; }
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            var willOpen = panel.hidden;
            closePops(button);
            panel.hidden = !willOpen;
            button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) {
                var first = panel.querySelector('a, button');
                if (first) { first.focus(); }
            }
        });
        panel.addEventListener('click', function (event) { event.stopPropagation(); });
    });

    document.addEventListener('click', function () { closePops(null); });

    /* ---------------- keep the current item in view ---------------- */
    if (sidebar) {
        var active = sidebar.querySelector('.app-nav-link.is-active');
        if (active && active.scrollIntoView) {
            active.scrollIntoView({ block: 'nearest' });
        }
    }
})();
