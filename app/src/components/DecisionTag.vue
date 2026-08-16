<script setup>
import { computed } from 'vue';

const props = defineProps({
  decision: { type: String, required: true },
  provisional: { type: Boolean, default: false },
});

const map = {
  auto: { variant: 'ok', label: 'allowed' },
  require: { variant: 'write', label: 'needs approval' },
  block: { variant: 'danger', label: 'blocked' },
};

const shape = computed(() => map[props.decision] || { variant: 'mute', label: props.decision });
</script>

<template>
  <span
    class="mag-tag"
    :class="[`mag-tag--${shape.variant}`, { 'mag-tag--unverified': provisional }]"
    :title="provisional ? 'Allowed on trust for now — being verified as it runs.' : null"
  >{{ shape.label }}</span>
</template>
