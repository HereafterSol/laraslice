{{--
    Tab-ribbon overflow collapse (layout kit): upgrades every
    [data-ls-ribbon] strip on the page. Tabs that do not fit move
    into a "More" dropdown — the source grids' behaviour — instead
    of scrolling horizontally. The active tab is never overflowed.
    Self-guarding: including this partial more than once is a no-op.
--}}
<script>
if (!window.__lsTabRibbon) {
    window.__lsTabRibbon = true;
    (function () {
        var MORE_W = 96, GAP = 4;

        function tabsOf(box) {
            return Array.prototype.filter.call(box.children, function (el) {
                return el.tagName === 'BUTTON' && el.hasAttribute('data-ls-tab');
            });
        }

        function buildMore(box) {
            var wrap = document.createElement('div');
            wrap.setAttribute('data-ls-ribbon-more', '');
            wrap.style.cssText = 'position:relative;flex:none;display:none;';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('aria-haspopup', 'true');
            btn.setAttribute('aria-expanded', 'false');
            btn.className = 'px-4 py-2 text-[13px] font-medium border-b-2 border-transparent text-muted-foreground hover:text-foreground transition-colors flex items-center gap-1.5 whitespace-nowrap cursor-pointer';
            btn.innerHTML = '<span>More</span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
            var menu = document.createElement('div');
            menu.setAttribute('role', 'menu');
            menu.style.cssText = 'position:absolute;right:0;top:100%;z-index:50;min-width:11rem;display:none;';
            menu.className = 'rounded-xl border border-border bg-card p-1 shadow-lg';
            wrap.appendChild(btn);
            wrap.appendChild(menu);
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = menu.style.display !== 'none';
                closeAllMenus();
                if (!open) {
                    menu.style.display = 'block';
                    btn.setAttribute('aria-expanded', 'true');
                }
            });
            menu.addEventListener('click', function () {
                menu.style.display = 'none';
                btn.setAttribute('aria-expanded', 'false');
            });
            var actions = box.querySelector('[data-ls-ribbon-actions]');
            if (actions) {
                box.insertBefore(wrap, actions);
            } else {
                box.appendChild(wrap);
            }
            return { wrap: wrap, btn: btn, menu: menu };
        }

        function closeAllMenus() {
            document.querySelectorAll('[data-ls-ribbon-more] > div').forEach(function (m) {
                m.style.display = 'none';
            });
            document.querySelectorAll('[data-ls-ribbon-more] > button').forEach(function (b) {
                b.setAttribute('aria-expanded', 'false');
            });
        }
        document.addEventListener('click', closeAllMenus);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAllMenus();
        });

        function layout(box) {
            var tabs = tabsOf(box);
            if (!tabs.length) return;
            var more = box.__lsMore || (box.__lsMore = buildMore(box));
            tabs.forEach(function (t) {
                t.style.display = '';
                t.style.flexShrink = '0';
            });
            more.wrap.style.display = 'none';
            more.menu.innerHTML = '';
            var actions = box.querySelector('[data-ls-ribbon-actions]');
            var avail = box.clientWidth - (actions ? actions.offsetWidth + 12 : 0) - 8;
            var widths = tabs.map(function (t) { return t.offsetWidth + GAP; });
            var total = widths.reduce(function (a, b) { return a + b; }, 0);
            if (total <= avail) return;

            var active = box.__lsActive || (new URLSearchParams(window.location.search).get('tab')) || tabs[0].getAttribute('data-ls-tab');
            var limit = avail - MORE_W, used = 0, visible = [], overflow = [];
            tabs.forEach(function (t, i) {
                if (used + widths[i] <= limit) {
                    visible.push(t);
                    used += widths[i];
                } else {
                    overflow.push(t);
                }
            });
            // The active tab is never overflowed: swap it with the
            // last visible non-active tab when needed.
            var activeTab = tabs.filter(function (t) { return t.getAttribute('data-ls-tab') === active; })[0];
            if (activeTab && overflow.indexOf(activeTab) !== -1) {
                var swap = null;
                for (var i = visible.length - 1; i >= 0; i--) {
                    if (visible[i] !== activeTab) { swap = visible[i]; break; }
                }
                if (swap) {
                    visible[visible.indexOf(swap)] = activeTab;
                    overflow[overflow.indexOf(activeTab)] = swap;
                }
            }
            overflow.forEach(function (t) {
                t.style.display = 'none';
                var item = document.createElement('button');
                item.type = 'button';
                item.setAttribute('role', 'menuitem');
                item.className = 'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-[13px] font-medium text-muted-foreground hover:bg-muted hover:text-foreground transition-colors whitespace-nowrap cursor-pointer';
                if (t.getAttribute('data-ls-tab') === active) {
                    item.classList.add('text-primary');
                }
                item.textContent = t.textContent.trim().replace(/\s+/g, ' ');
                item.addEventListener('click', function () { t.click(); });
                more.menu.appendChild(item);
            });
            if (overflow.length) {
                more.wrap.style.display = '';
            }
        }

        function upgrade() {
            document.querySelectorAll('[data-ls-ribbon]').forEach(function (box) {
                if (!box.__lsUpgraded) {
                    box.__lsUpgraded = true;
                    box.addEventListener('click', function (e) {
                        var t = e.target.closest('button[data-ls-tab]');
                        if (t) {
                            box.__lsActive = t.getAttribute('data-ls-tab');
                            setTimeout(function () { layout(box); }, 30);
                        }
                    });
                }
                layout(box);
            });
        }

        var raf = null;
        window.addEventListener('resize', function () {
            if (raf) cancelAnimationFrame(raf);
            raf = requestAnimationFrame(upgrade);
        });
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', upgrade);
        } else {
            upgrade();
        }
        window.addEventListener('load', upgrade);
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () { setTimeout(upgrade, 30); });
        }
    })();
}
</script>
