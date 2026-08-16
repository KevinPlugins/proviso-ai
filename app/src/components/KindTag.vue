<script setup>
import { computed } from 'vue';

/**
 * The read/write marker.
 *
 * The basis matters as much as the verdict: a classification inferred from an
 * ability's name is drawn as a dashed outline so it never reads as settled fact
 * next to one earned by three observed executions.
 */
const props = defineProps({
  kind: { type: String, required: true },
  basis: { type: String, default: 'observed' },
});

const verified = computed(() => props.basis === 'observed');

const variant = computed(() => ({
  read: 'read',
  write: 'write',
  unknown: 'mute',
}[props.kind] || 'mute'));

const title = computed(() => {
  if (props.kind === 'unknown') return 'Never run, and its name gives nothing away.';
  if (verified.value) {
    return props.kind === 'read'
      ? 'Observed running without writing anything.'
      : 'Observed writing.';
  }
  return props.basis === 'declared'
    ? 'Declared by the ability itself. Not yet verified.'
    : 'Inferred from its name and arguments. Not yet verified.';
});
</script>

<template>
  <span
    class="mag-tag"
    :class="[`mag-tag--${variant}`, { 'mag-tag--unverified': !verified }]"
    :title="title"
  >
    <span class="mag-dot" aria-hidden="true"></span>{{ kind }}
  </span>
</template>
