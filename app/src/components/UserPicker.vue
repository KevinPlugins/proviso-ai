<script setup>
import { computed, ref } from 'vue';
import { store } from '../store';
import { usePopover } from '../usePlacement';

/**
 * Choose approvers as people.
 *
 * Roles were the obvious first design and the wrong one: picking "Editor" tells
 * you nothing about who will actually be notified, and the set changes silently
 * whenever somebody's role changes. Named people make the consequence visible.
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  quorum: { type: String, default: 'any' },
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'update:quorum']);

const search = ref('');
const trigger = ref(null);
const { open, style, menuEl, toggle, close } = usePopover(330, 288);

const users = computed(() => store.meta.users || []);

const matches = computed(() => {
  const term = search.value.trim().toLowerCase();
  if (!term) return users.value;
  return users.value.filter(
    (user) =>
      user.label.toLowerCase().includes(term) || user.login.toLowerCase().includes(term)
  );
});

const chosen = computed(() =>
  users.value.filter((user) => props.modelValue.includes(user.value))
);

function toggleUser(user) {
  emit(
    'update:modelValue',
    props.modelValue.includes(user.value)
      ? props.modelValue.filter((value) => value !== user.value)
      : [...props.modelValue, user.value]
  );
}
</script>

<template>
  <div class="mag-picker">
    <button
      ref="trigger"
      type="button"
      class="mag-picker__trigger"
      :disabled="disabled"
      :aria-expanded="open"
      @click="toggle(trigger)"
    >
      <template v-if="chosen.length">
        <span class="mag-faces">
          <img
            v-for="user in chosen.slice(0, 4)"
            :key="user.value"
            :src="user.avatar"
            :alt="user.label"
            :title="user.label"
            class="mag-face"
          />
        </span>
        <span class="mag-picker__label">
          {{ chosen.length === 1 ? chosen[0].label : `${chosen.length} approvers` }}
        </span>
      </template>

      <span v-else class="mag-picker__label mag-picker__label--empty">
        Administrators (default)
      </span>

      <svg class="mag-dd__chev" width="10" height="6" viewBox="0 0 10 6" aria-hidden="true">
        <path d="M1 1l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
      </svg>
    </button>

    <Teleport to="body">
      <div v-if="open" ref="menuEl" class="mag-menu mag-menu--wide" :style="style">
        <div class="mag-menu__search">
          <input
            v-model="search"
            class="mag-input"
            type="search"
            placeholder="Search people…"
            @keydown.esc="close"
          />
        </div>

        <div class="mag-menu__scroll">
          <button
            v-for="user in matches"
            :key="user.value"
            type="button"
            class="mag-person"
            :class="{ 'is-selected': modelValue.includes(user.value) }"
            @click="toggleUser(user)"
          >
            <span class="mag-person__check" aria-hidden="true">
              <svg v-if="modelValue.includes(user.value)" width="11" height="9" viewBox="0 0 11 9">
                <path d="M1 4.5L4 7.5L10 1.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </span>
            <img :src="user.avatar" alt="" class="mag-face" />
            <span class="mag-person__text">
              <span class="mag-person__name">{{ user.label }}</span>
              <span class="mag-person__meta">{{ user.role }}</span>
            </span>
          </button>

          <p v-if="!matches.length" class="mag-menu__none">Nobody matches.</p>
        </div>

        <div class="mag-menu__foot">
          <label class="mag-quorum">
            <input type="radio" :checked="quorum === 'any'" @change="emit('update:quorum', 'any')" />
            <span>Any one approves</span>
          </label>
          <label class="mag-quorum">
            <input type="radio" :checked="quorum === 'all'" @change="emit('update:quorum', 'all')" />
            <span>All must approve</span>
          </label>
        </div>
      </div>
    </Teleport>
  </div>
</template>
