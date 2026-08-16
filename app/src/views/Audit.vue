<script setup>
import { computed, ref } from 'vue';
import { store, undoEntry } from '../store';
import BaseSelect from '../components/BaseSelect.vue';

const filter = ref('all');

const filterOptions = [
  { value: 'all', label: 'Everything' },
  { value: 'writes', label: 'Changed something', dot: 'write' },
  { value: 'auto', label: 'Allowed automatically', dot: 'ok' },
  { value: 'require', label: 'Queued for approval', dot: 'write' },
  { value: 'approve', label: 'Human approved', dot: 'ok' },
  { value: 'block', label: 'Blocked', dot: 'danger' },
  { value: 'violation', label: 'Broken read-only claims', dot: 'danger' },
];
const busy = ref(null);

const decisionVariants = {
  auto: 'ok',
  approve: 'ok',
  require: 'write',
  block: 'danger',
  violation: 'danger',
  reject: 'mute',
  timeout: 'mute',
  undo: 'mute',
};

const visible = computed(() => {
  if (filter.value === 'all') return store.audit;
  if (filter.value === 'writes') return store.audit.filter((e) => e.footprint.length);
  return store.audit.filter((e) => e.decision === filter.value);
});

async function undo(entry) {
  busy.value = entry.id;
  await undoEntry(entry.id);
  busy.value = null;
}
</script>

<template>
  <div>
    <h2 class="mag-title">Audit log</h2>
    <p class="mag-lede">
      Every governed call on this site — allowed, queued, blocked or reverted — regardless of
      which plugin exposed the ability.
    </p>

    <div v-if="store.meta.sharedAccounts.length" class="mag-note">
      <strong>Several clients share one WordPress account.</strong>
      <div v-for="row in store.meta.sharedAccounts" :key="row.user" style="margin-top: 4px">
        {{ row.user }} is used by {{ row.requesters.join(', ') }}
      </div>
      <div style="margin-top: 6px">
        Per-caller rules cannot apply and actions cannot be attributed to one client. Give each
        agent its own WordPress user.
      </div>
    </div>

    <div class="mag-filters">
      <BaseSelect v-model="filter" :options="filterOptions" />
    </div>

    <div class="mag-panel">
      <table class="mag-table">
        <thead>
          <tr>
            <th style="width: 150px">When</th>
            <th>Ability</th>
            <th style="width: 170px">Caller</th>
            <th style="width: 120px">Outcome</th>
            <th>Changed</th>
            <th style="width: 150px">Undo</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="entry in visible" :key="entry.id">
            <td class="mag-meta">{{ entry.createdAt }}</td>

            <td>
              <span class="mag-ability" style="cursor: default">
                <span class="mag-ability__ns">{{ entry.ability.split('/')[0] }}/</span
                ><span class="mag-ability__slug">{{ entry.ability.split('/')[1] }}</span>
              </span>
            </td>

            <td>
              <div class="mag-meta">{{ entry.requester.label }}</div>
              <div class="mag-meta mag-meta--dim">{{ entry.requester.tier || '—' }}</div>
            </td>

            <td>
              <span class="mag-tag" :class="`mag-tag--${decisionVariants[entry.decision] || 'mute'}`">
                {{ entry.decision }}
              </span>
              <div class="mag-meta mag-meta--dim" style="margin-top: 4px">{{ entry.outcome }}</div>
            </td>

            <td>
              <div v-if="entry.footprint.length" class="mag-ops">
                <span v-for="op in entry.footprint" :key="op" class="mag-op">{{ op }}</span>
              </div>
              <span v-else class="mag-empty-cell">—</span>
            </td>

            <td>
              <span v-if="entry.undoneAt" class="mag-tag mag-tag--mute">reverted</span>

              <template v-else-if="entry.undo.possible">
                <button
                  class="mag-btn mag-btn--sm"
                  :disabled="busy === entry.id || !store.meta.canManage"
                  @click="undo(entry)"
                >Undo</button>
                <div v-if="entry.undo.partial" class="mag-meta mag-meta--dim" style="margin-top: 4px">
                  partly — {{ entry.undo.blocked.length }} step(s) cannot revert
                </div>
              </template>

              <span
                v-else-if="entry.undo.blocked.length"
                class="mag-meta mag-meta--dim"
                :title="entry.undo.blocked.join('; ')"
              >not reversible</span>

              <span v-else class="mag-meta mag-meta--dim">—</span>
            </td>
          </tr>

          <tr v-if="!visible.length">
            <td colspan="6">
              <div class="mag-empty">
                <div class="mag-empty__title">Nothing recorded</div>
                <div>Calls appear here as agents use the site.</div>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
