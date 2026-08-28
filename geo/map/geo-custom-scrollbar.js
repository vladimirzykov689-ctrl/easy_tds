(function () {
    if (!document.getElementById('geo-custom-scrollbar-styles')) {
        var styleTag = document.createElement('style');
        styleTag.id = 'geo-custom-scrollbar-styles';
        styleTag.textContent = [
            '.geo-scroll-viewport {',
            '  position: absolute;',
            '  top: 0;',
            '  left: 0;',
            '  right: 0;',
            '  bottom: 0;',
            '  overflow-y: hidden;',
            '}',
            '.geo-scroll-track {',
            '  position: absolute;',
            '  top: 10px;',
            '  right: 4px;',
            '  bottom: 10px;',
            '  width: 4px;',
            '  background: rgba(255,255,255,0.08);',
            '  border-radius: 4px;',
            '  z-index: 7;',
            '}',
            '.geo-scroll-thumb {',
            '  position: absolute;',
            '  left: 0;',
            '  width: 4px;',
            '  border-radius: 4px;',
            '  background: linear-gradient(180deg, #ff2fd0, #9b00ff);',
            '  cursor: pointer;',
            '  transition: background 0.15s ease;',
            '}',
            '.geo-scroll-thumb:hover {',
            '  background: #ff2fd0;',
            '  box-shadow: 0 0 6px rgba(255,0,255,0.5);',
            '}'
        ].join('\n');
        document.head.appendChild(styleTag);
    }

    function initCustomScrollbar(outer) {

        if (outer.dataset.customScrollInit) return;
        outer.dataset.customScrollInit = '1';
        
        outer.style.overflow = 'hidden';
        
        var lockedHeight = outer.getBoundingClientRect().height;
        outer.style.height = lockedHeight + 'px';
        outer.style.maxHeight = lockedHeight + 'px';

        var computedPosition = getComputedStyle(outer).position;
        if (computedPosition === 'static') {
            outer.style.position = 'relative';
        }
        
        var viewport = document.createElement('div');
        viewport.className = 'geo-scroll-viewport';
        while (outer.firstChild) {
            viewport.appendChild(outer.firstChild);
        }
        outer.appendChild(viewport);
        viewport.style.paddingRight = '10px';
        viewport.style.paddingTop = getComputedStyle(outer).paddingTop;
        viewport.style.paddingLeft = getComputedStyle(outer).paddingLeft;
        viewport.style.paddingBottom = getComputedStyle(outer).paddingBottom;
        outer.style.padding = '0';

        var container = viewport;

        var track = document.createElement('div');
        track.className = 'geo-scroll-track';

        var thumb = document.createElement('div');
        thumb.className = 'geo-scroll-thumb';
        track.appendChild(thumb);
        outer.appendChild(track);

        function updateThumb() {
            var contentH = container.scrollHeight;
            var visibleH = container.clientHeight;

            if (contentH <= visibleH + 1) {
                track.style.display = 'none';
                return;
            }
            track.style.display = 'block';

            var trackH = track.clientHeight;
            var thumbH = Math.max((visibleH / contentH) * trackH, 24);
            var maxThumbTop = trackH - thumbH;
            var maxScrollTop = contentH - visibleH;
            var scrollRatio = maxScrollTop > 0 ? container.scrollTop / maxScrollTop : 0;

            thumb.style.height = thumbH + 'px';
            thumb.style.top = (scrollRatio * maxThumbTop) + 'px';
        }

        container.addEventListener('scroll', updateThumb, { passive: true });

        container.addEventListener('wheel', function (e) {
            e.preventDefault();
            var maxScrollTop = container.scrollHeight - container.clientHeight;
            var next = container.scrollTop + e.deltaY;
            if (next < 0) next = 0;
            if (next > maxScrollTop) next = maxScrollTop;
            container.scrollTop = next;
            updateThumb();
        }, { passive: false });

        var dragging = false;
        var startY = 0;
        var startScrollTop = 0;

        thumb.addEventListener('mousedown', function (e) {
            dragging = true;
            startY = e.clientY;
            startScrollTop = container.scrollTop;
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var contentH = container.scrollHeight;
            var visibleH = container.clientHeight;
            var trackH = track.clientHeight;
            var deltaY = e.clientY - startY;
            var scrollableTrack = trackH - thumb.offsetHeight;
            if (scrollableTrack <= 0) return;
            var scrollDelta = (deltaY / scrollableTrack) * (contentH - visibleH);

            var next = startScrollTop + scrollDelta;
            var maxScrollTop = contentH - visibleH;
            if (next < 0) next = 0;
            if (next > maxScrollTop) next = maxScrollTop;

            container.scrollTop = next;
            updateThumb();
        });

        document.addEventListener('mouseup', function () {
            dragging = false;
            document.body.style.userSelect = '';
        });

        track.addEventListener('mousedown', function (e) {
            if (e.target === thumb) return;
            var rect = track.getBoundingClientRect();
            var clickY = e.clientY - rect.top;
            var contentH = container.scrollHeight;
            var visibleH = container.clientHeight;
            var trackH = track.clientHeight;
            var ratio = clickY / trackH;
            container.scrollTop = ratio * (contentH - visibleH);
            updateThumb();
        });

        var resizeObserver = new ResizeObserver(updateThumb);
        resizeObserver.observe(container);

        var mutationObserver = new MutationObserver(updateThumb);
        mutationObserver.observe(container, { childList: true, subtree: true });

        updateThumb();
    }

    function initAll() {
        document.querySelectorAll('.geo-list-overlay').forEach(initCustomScrollbar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    var bodyObserver = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return;
                if (node.classList && node.classList.contains('geo-list-overlay')) {
                    initCustomScrollbar(node);
                } else if (node.querySelectorAll) {
                    node.querySelectorAll('.geo-list-overlay').forEach(initCustomScrollbar);
                }
            });
        });
    });
    bodyObserver.observe(document.body, { childList: true, subtree: true });
})();
