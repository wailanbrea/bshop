let locks = 0;
let overflow = '';
let padding = '';

/** Shared by dialogs and the navigation drawer; nested overlays restore only once. */
export function lockPageScroll(): () => void {
    if (locks++ === 0) {
        overflow = document.body.style.overflow;
        padding = document.body.style.paddingRight;
        const scrollbar = window.innerWidth - document.documentElement.clientWidth;
        document.body.style.overflow = 'hidden';
        if (scrollbar > 0) document.body.style.paddingRight = `${scrollbar}px`;
    }
    let released = false;
    return () => {
        if (released) return;
        released = true;
        if (--locks === 0) {
            document.body.style.overflow = overflow;
            document.body.style.paddingRight = padding;
        }
    };
}
