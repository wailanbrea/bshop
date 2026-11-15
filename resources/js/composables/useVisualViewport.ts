import { onMounted, onUnmounted } from 'vue';

/** Keeps overlays in the visible area when a tablet keyboard reduces the viewport. */
export function useVisualViewport() {
    let frame = 0;
    let scrollTimer: ReturnType<typeof setTimeout> | undefined;
    function update() {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => {
            const viewport = window.visualViewport;
            // Do not fight the user's pinch zoom.
            const unzoomed = !viewport || Math.abs(viewport.scale - 1) < 0.01;
            document.documentElement.style.setProperty('--app-viewport-height', `${unzoomed && viewport ? viewport.height : window.innerHeight}px`);
            document.documentElement.style.setProperty('--app-viewport-top', `${unzoomed && viewport ? viewport.offsetTop : 0}px`);
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(() => {
                const active = document.activeElement;
                if (unzoomed && active instanceof HTMLElement && active.matches('input,select,textarea') && active.closest('dialog[open]')) {
                    active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                }
            }, 120);
        });
    }
    onMounted(() => {
        update();
        window.addEventListener('resize', update);
        window.visualViewport?.addEventListener('resize', update);
        window.visualViewport?.addEventListener('scroll', update);
    });
    onUnmounted(() => {
        cancelAnimationFrame(frame);
        clearTimeout(scrollTimer);
        window.removeEventListener('resize', update);
        window.visualViewport?.removeEventListener('resize', update);
        window.visualViewport?.removeEventListener('scroll', update);
        document.documentElement.style.removeProperty('--app-viewport-height');
        document.documentElement.style.removeProperty('--app-viewport-top');
    });
}
