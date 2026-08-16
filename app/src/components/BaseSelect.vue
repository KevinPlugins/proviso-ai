<script setup>
import { computed, ref } from 'vue';
import { usePopover } from '../usePlacement';

/**
 * A real dropdown rather than a styled <select>.
 *
 * Native selects cannot carry a description per option, and the platform menu
 * ignores every style you give it. Options here explain what they do, which
 * matters when the choice is "block this ability entirely".
 */
const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, required: true },
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const trigger = ref(null);
const activeIndex = ref(-1);
const { open, style, menuEl, toggle, close } = usePopover(220, 240);

const selected = computed(
  () => props.options.find((option) => option.value === props.modelValue) || props.options[0]
);

function onTrigger() {
  if (props.disabled) return;
  activeIndex.value = props.options.findIndex((o) => o.value === props.modelValue);
  toggle(trigger.value);
}

function choose(option) {
  emit('update:modelValue', option.value);
  close();
}

function onKey(event) {
  if (!open.value) return;

  if (event.key === 'Escape') {
    close();
    return;
  }
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault();
    const step = event.key === 'ArrowDown' ? 1 : -1;
    activeIndex.value = (activeIndex.value + step + props.options.length) % props.options.length;
    return;
  }
  if ((event.key === 'Enter' || event.key === ' ') && activeIndex.value >= 0) {
    event.preventDefault();
    choose(props.options[activeIndex.value]);
  }
}
</script>

<template>
  <div class="mag-dd" :class="{ 'is-open': open }">
    <button
      ref="trigger"
      type="button"
      class="mag-dd__trigger"
      :disabled="disabled"
      :aria-expanded="open"
      @click="onTrigger"
      @keydown="onKey"
    >
      <span class="mag-dd__value">
        <span v-if="selected?.dot" class="mag-dd__dot" :class="`mag-dd__dot--${selected.dot}`"></span>
        {{ selected?.label }}
      </span>
      <svg class="mag-dd__chev" width="10" height="6" viewBox="0 0 10 6" aria-hidden="true">
        <path d="M1 1l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
      </svg>
    </button>

    <Teleport to="body">
      <div
        v-if="open"
        ref="menuEl"
        class="mag-menu"
        :style="style"
        role="listbox"
        @keydown="onKey"
      >
        <button
          v-for="(option, index) in options"
          :key="option.value"
          type="button"
          role="option"
          class="mag-menu__item"
          :class="{ 'is-active': index === activeIndex, 'is-selected': option.value === modelValue }"
          :aria-selected="option.value === modelValue"
          @click="choose(option)"
          @mouseenter="activeIndex = index"
        >
          <span class="mag-menu__main">
            <span v-if="option.dot" class="mag-dd__dot" :class="`mag-dd__dot--${option.dot}`"></span>
            <span>{{ option.label }}</span>
          </span>
          <span v-if="option.hint" class="mag-menu__hint">{{ option.hint }}</span>
        </button>
      </div>
    </Teleport>
  </div>
</template>
