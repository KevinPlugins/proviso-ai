<script setup>
import { computed, onMounted } from 'vue';
import { store, load, cfg } from './store';
import Abilities from './views/Abilities.vue';
import Queue from './views/Queue.vue';
import Audit from './views/Audit.vue';
import Settings from './views/Settings.vue';

const views = { abilities: Abilities, queue: Queue, audit: Audit, settings: Settings };

const tabs = computed(() => [
  { id: 'abilities', label: 'Abilities', count: 0 },
  { id: 'queue', label: 'Queue', count: store.requests.length },
  { id: 'audit', label: 'Audit', count: 0 },
  { id: 'settings', label: 'Settings', count: 0 },
]);

const current = computed(() => views[store.view] || Abilities);

const summary = computed(() => {
  const reads = store.abilities.filter((a) => a.kind === 'read').length;
  const writes = store.abilities.filter((a) => a.kind === 'write').length;
  return `${store.abilities.length} abilities · ${reads} read · ${writes} write`;
});

onMounted(() => {
  // Deep links from the wp-admin submenu.
  const requested = new URLSearchParams(window.location.search).get('view');
  if (requested && views[requested]) store.view = requested;
  load();
});

function go(id) {
  store.view = id;
  // Otherwise a tab switch lands mid-page with the heading behind the sticky
  // header, because the previous view was scrolled.
  window.scrollTo({ top: 0 });
  const url = new URL(window.location.href);
  url.searchParams.set('view', id);
  window.history.replaceState({}, '', url);
}
</script>

<template>
  <div class="mag-app">
    <header class="mag-header">
      <div class="mag-header__inner">
        <div class="mag-brand">
          <h1 class="mag-brand__name">Ability Guard</h1>
          <span class="mag-brand__meta">{{ store.ready ? summary : 'loading…' }}</span>
        </div>

        <nav class="mag-nav">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            class="mag-nav__item"
            :class="{ 'is-active': store.view === tab.id }"
            @click="go(tab.id)"
          >
            {{ tab.label }}
            <span v-if="tab.count" class="mag-nav__count">{{ tab.count }}</span>
          </button>
        </nav>
      </div>
    </header>

    <main class="mag-main">
      <div v-if="!store.ready && store.loading" class="mag-empty">
        <span class="mag-spinner"></span>
      </div>

      <component :is="current" v-else-if="store.ready" />

      <div v-else class="mag-empty">
        <div class="mag-empty__title">Could not load</div>
        <div>Check that the plugin's tables exist, then reload.</div>
      </div>
    </main>

    <div v-if="store.toast" class="mag-toast" :class="{ 'mag-toast--error': store.toast.type === 'error' }">
      {{ store.toast.message }}
    </div>
  </div>
</template>
