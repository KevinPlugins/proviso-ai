import { onBeforeUnmount, ref } from 'vue';

/**
 * Open a popover in a portal, positioned against its trigger.
 *
 * Absolute positioning inside the component looks correct until the popover has
 * to escape a container with `overflow: hidden` — which every panel and table on
 * this screen has, for its rounded corners. The menu was being clipped rather
 * than mispositioned, so no amount of top/bottom juggling fixes it. Rendering to
 * <body> with fixed coordinates does.
 */
export function usePopover(estimatedHeight = 300, minWidth = 0) {
  const open = ref(false);
  const style = ref({});
  const triggerEl = ref(null);
  const menuEl = ref(null);

  function place() {
    const el = triggerEl.value;
    if (!el) return;

    const rect = el.getBoundingClientRect();
    const below = window.innerHeight - rect.bottom;
    const above = rect.top;

    // Flip up only when below is too tight AND above is genuinely roomier, so
    // the menu never jumps into an equally cramped space.
    const up = below < estimatedHeight && above > below;

    const width = Math.max(rect.width, minWidth);
    const maxLeft = window.innerWidth - width - 12;

    style.value = {
      position: 'fixed',
      left: `${Math.max(12, Math.min(rect.left, maxLeft))}px`,
      width: `${width}px`,
      ...(up
        ? { bottom: `${window.innerHeight - rect.top + 5}px` }
        : { top: `${rect.bottom + 5}px` }),
      maxHeight: `${Math.max(180, (up ? above : below) - 16)}px`,
    };
  }

  function onScroll() {
    if (open.value) place();
  }

  // The menu is portalled to <body> and nothing inside it holds focus, so a
  // keydown handler on the component never sees Escape. Listen at the document.
  function onKeydown(event) {
    if (event.key === 'Escape' && open.value) {
      event.stopPropagation();
      close();
      triggerEl.value?.focus();
    }
  }

  function onPointerDown(event) {
    const inTrigger = triggerEl.value?.contains(event.target);
    const inMenu = menuEl.value?.contains(event.target);
    if (!inTrigger && !inMenu) close();
  }

  function listen(active) {
    const method = active ? 'addEventListener' : 'removeEventListener';
    window[method]('scroll', onScroll, true);
    window[method]('resize', onScroll);
    document[method]('mousedown', onPointerDown, true);
    document[method]('keydown', onKeydown, true);
  }

  function openAt(el) {
    triggerEl.value = el;
    open.value = true;
    place();
    listen(true);
  }

  function close() {
    open.value = false;
    listen(false);
  }

  function toggle(el) {
    if (open.value) close();
    else openAt(el);
  }

  onBeforeUnmount(() => listen(false));

  return { open, style, menuEl, openAt, close, toggle, place };
}
