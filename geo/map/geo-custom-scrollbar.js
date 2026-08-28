/**
 * Кастомный скроллбар для .geo-list-overlay
 * Убирает системный скролл (стрелки/полоса браузера) и рисует свою тонкую
 * полоску-ползунок, которым можно тащить мышкой или колесом.
 *
 * Подключение: просто добавьте <script src="geo-custom-scrollbar.js"></script>
 * после того как блок .geo-list-overlay появится в DOM. Ничего в HTML менять не нужно.
 */
(function () {
    function initCustomScrollbar(container) {
        // Если уже инициализирован — не дублируем
        if (container.dataset.customScrollInit) return;
        container.dataset.customScrollInit = '1';

        // Прячем нативный скролл, но оставляем возможность скроллить контент
        container.style.overflowY = 'hidden';
        container.style.position = container.style.position || 'relative';
        container.style.paddingRight = '14px'; // место под свою полоску

        // Трек
        const track = document.createElement('div');
        track.className = 'geo-scroll-track';

        // Ползунок
        const thumb = document.createElement('div');
        thumb.className = 'geo-scroll-thumb';
        track.appendChild(thumb);
        container.appendChild(track);

        function updateThumb() {
            const contentH = container.scrollHeight;
            const visibleH = container.clientHeight;

            if (contentH <= visibleH) {
                track.style.display = 'none';
                return;
            }
            track.style.display = 'block';

            const thumbH = Math.max((visibleH / contentH) * visibleH, 24);
            const maxThumbTop = visibleH - thumbH;
            const scrollRatio = container.scrollTop / (contentH - visibleH);

            thumb.style.height = thumbH + 'px';
            thumb.style.top = (scrollRatio * maxThumbTop) + 'px';
        }

        // Скролл колесом мыши внутри блока
        container.addEventListener('wheel', function (e) {
            e.preventDefault();
            container.scrollTop += e.deltaY;
            updateThumb();
        }, { passive: false });

        // Драг ползунка мышкой
        let dragging = false;
        let startY = 0;
        let startScrollTop = 0;

        thumb.addEventListener('mousedown', function (e) {
            dragging = true;
            startY = e.clientY;
            startScrollTop = container.scrollTop;
            document.body.style.userSelect = 'none';
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            const contentH = container.scrollHeight;
            const visibleH = container.clientHeight;
            const deltaY = e.clientY - startY;
            const scrollableTrack = visibleH - thumb.offsetHeight;
            const scrollDelta = (deltaY / scrollableTrack) * (contentH - visibleH);

            container.scrollTop = startScrollTop + scrollDelta;
            updateThumb();
        });

        document.addEventListener('mouseup', function () {
            dragging = false;
            document.body.style.userSelect = '';
        });

        // Клик по треку — прыжок к позиции
        track.addEventListener('mousedown', function (e) {
            if (e.target === thumb) return;
            const rect = track.getBoundingClientRect();
            const clickY = e.clientY - rect.top;
            const contentH = container.scrollHeight;
            const visibleH = container.clientHeight;
            const ratio = clickY / visibleH;
            container.scrollTop = ratio * (contentH - visibleH);
            updateThumb();
        });

        // Пересчёт при ресайзе и при изменении контента (напр. AJAX-обновление гео)
        const resizeObserver = new ResizeObserver(updateThumb);
        resizeObserver.observe(container);

        const mutationObserver = new MutationObserver(updateThumb);
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

    // На случай если блок .geo-list-overlay добавляется в DOM позже (AJAX-рендер карты)
    const bodyObserver = new MutationObserver(function (mutations) {
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
