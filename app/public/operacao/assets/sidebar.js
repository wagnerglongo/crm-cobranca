(function () {
    'use strict';

    const COLLAPSED_KEY = 'works.sidebar.collapsed';
    const GROUPS_KEY = 'works.sidebar.groups.v2';

    function readStorage(key, fallback) {
        try {
            const value = window.localStorage.getItem(key);
            return value === null ? fallback : value;
        } catch (error) {
            return fallback;
        }
    }

    function writeStorage(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (error) {
            // Navegação continua funcional mesmo quando o storage estiver bloqueado.
        }
    }

    function parseGroupState() {
        const stored = readStorage(GROUPS_KEY, '{}');
        try {
            const parsed = JSON.parse(stored);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function initSidebar() {
        const shell = document.querySelector('.app-shell');
        const sidebar = shell ? shell.querySelector('.sidebar') : null;
        const toggle = sidebar ? sidebar.querySelector('[data-sidebar-toggle]') : null;
        if (!shell || !sidebar || !toggle || shell.dataset.sidebarReady === '1') {
            return;
        }

        shell.dataset.sidebarReady = '1';
        const groups = Array.from(sidebar.querySelectorAll('[data-nav-group]'));
        const groupState = parseGroupState();
        const desktopMedia = typeof window.matchMedia === 'function'
            ? window.matchMedia('(min-width: 1181px)')
            : null;

        function persistGroups() {
            writeStorage(GROUPS_KEY, JSON.stringify(groupState));
        }

        function setSidebarState(collapsed, persist) {
            shell.classList.toggle('sidebar-collapsed', collapsed);
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', collapsed ? 'Expandir menu lateral' : 'Minimizar menu lateral');

            const text = toggle.querySelector('.sidebar-toggle-text');
            if (text) {
                text.textContent = collapsed ? 'Expandir menu' : 'Minimizar menu';
            }

            if (persist) {
                writeStorage(COLLAPSED_KEY, collapsed ? '1' : '0');
            }
        }

        function setGroupState(group, open, persist) {
            const button = group.querySelector('.nav-group-toggle');
            const key = group.getAttribute('data-nav-group') || '';
            group.classList.toggle('is-open', open);
            if (button) {
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            if (persist && key) {
                groupState[key] = open;
                persistGroups();
            }
        }

        groups.forEach(function (group) {
            const key = group.getAttribute('data-nav-group') || '';
            const containsCurrentPage = Boolean(group.querySelector('[aria-current="page"]'));
            const serverOpen = group.classList.contains('is-open');
            const storedOpen = Object.prototype.hasOwnProperty.call(groupState, key)
                ? groupState[key] === true
                : serverOpen;
            setGroupState(group, containsCurrentPage || storedOpen, false);
        });

        function restoreSidebarForViewport() {
            const canCollapse = desktopMedia === null || desktopMedia.matches;
            setSidebarState(canCollapse && readStorage(COLLAPSED_KEY, '0') === '1', false);
        }

        restoreSidebarForViewport();

        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            if (desktopMedia !== null && !desktopMedia.matches) {
                setSidebarState(false, false);
                return;
            }
            setSidebarState(!shell.classList.contains('sidebar-collapsed'), true);
        });

        if (desktopMedia !== null) {
            const onViewportChange = function () {
                restoreSidebarForViewport();
            };
            if (typeof desktopMedia.addEventListener === 'function') {
                desktopMedia.addEventListener('change', onViewportChange);
            } else if (typeof desktopMedia.addListener === 'function') {
                desktopMedia.addListener(onViewportChange);
            }
        }

        sidebar.addEventListener('click', function (event) {
            const button = event.target instanceof Element
                ? event.target.closest('.nav-group-toggle')
                : null;
            if (!button || !sidebar.contains(button)) {
                return;
            }

            const group = button.closest('[data-nav-group]');
            if (!group) {
                return;
            }

            event.preventDefault();
            if (shell.classList.contains('sidebar-collapsed')) {
                setSidebarState(false, true);
                setGroupState(group, true, true);
                return;
            }

            setGroupState(group, !group.classList.contains('is-open'), true);
        });

        window.addEventListener('pageshow', function () {
            sidebar.classList.remove('is-navigating');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar, {once: true});
    } else {
        initSidebar();
    }
})();
