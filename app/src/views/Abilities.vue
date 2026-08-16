<script setup>
import { computed, reactive, ref } from 'vue';
import { store, saveRule } from '../store';
import KindTag from '../components/KindTag.vue';
import BaseSelect from '../components/BaseSelect.vue';
import UserPicker from '../components/UserPicker.vue';

const search = ref('');
const kindFilter = ref('all');
const expanded = ref(null);

/** Unsaved approver edits, keyed by ability. */
const drafts = reactive({});

const ruleLabels = {
  auto: { label: 'Always allow', dot: 'ok', hint: 'Runs without asking.' },
  require: { label: 'Require approval', dot: 'write', hint: 'Queued for a person to review.' },
  block: { label: 'Block', dot: 'danger', hint: 'Refused, with an explanation to the agent.' },
};

const counts = computed(() => ({
  read: store.abilities.filter((a) => a.kind === 'read').length,
  write: store.abilities.filter((a) => a.kind === 'write').length,
  unknown: store.abilities.filter((a) => a.kind === 'unknown').length,
}));

const filters = computed(() => [
  { value: 'all', label: `All abilities`, hint: `${store.abilities.length} registered` },
  { value: 'read', label: 'Read only', dot: 'ok', hint: `${counts.value.read} abilities` },
  { value: 'write', label: 'Writes', dot: 'write', hint: `${counts.value.write} abilities` },
  { value: 'unknown', label: 'Unclassified', dot: 'mute', hint: `${counts.value.unknown} abilities` },
]);

const visible = computed(() => {
  const term = search.value.trim().toLowerCase();

  return store.abilities.filter((ability) => {
    if (kindFilter.value !== 'all' && ability.kind !== kindFilter.value) return false;
    if (!term) return true;
    return (
      ability.name.toLowerCase().includes(term) ||
      ability.label.toLowerCase().includes(term)
    );
  });
});

function options(ability) {
  return ability.choices.map((choice) => ({ value: choice, ...ruleLabels[choice] }));
}

/** What will actually happen — the stored rule, or the site default. */
function effective(ability) {
  return ability.rule || ability.decision;
}

function draftFor(ability) {
  if (!drafts[ability.name]) {
    drafts[ability.name] = {
      values: [...ability.approvers.values.filter((v) => v.startsWith('user:'))],
      quorum: ability.approvers.quorum,
    };
  }
  return drafts[ability.name];
}

function onRule(ability, rule) {
  saveRule(ability.name, { rule });
}

function onApprovers(ability, values) {
  draftFor(ability).values = values;
  saveRule(ability.name, { approvers: values, quorum: draftFor(ability).quorum });
}

function onQuorum(ability, quorum) {
  draftFor(ability).quorum = quorum;
  saveRule(ability.name, { approvers: draftFor(ability).values, quorum });
}

function toggle(name) {
  expanded.value = expanded.value === name ? null : name;
}
</script>

<template>
  <div>
    <h2 class="mag-title">Abilities</h2>
    <p class="mag-lede">
      Everything registered on this site, by any plugin, and what it has actually been observed
      to do. A dashed tag means the classification is inferred rather than proven.
    </p>

    <div class="mag-filters">
      <div class="mag-field mag-search">
        <span class="mag-field__icon">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
            <circle cx="6.2" cy="6.2" r="4.4" stroke="currentColor" stroke-width="1.5" />
            <path d="M9.6 9.6L12.5 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
          </svg>
        </span>
        <input v-model="search" class="mag-input" type="search" placeholder="Filter abilities…" />
      </div>
      <BaseSelect v-model="kindFilter" :options="filters" />
    </div>

    <div class="mag-panel">
      <table class="mag-table">
        <thead>
          <tr>
            <th style="width: 42%">Ability</th>
            <th>Observed behaviour</th>
            <th style="width: 300px">Rule</th>
          </tr>
        </thead>

        <tbody>
          <template v-for="ability in visible" :key="ability.name">
            <tr>
              <td>
                <div class="mag-row" style="gap: 8px">
                  <KindTag :kind="ability.kind" :basis="ability.kindBasis" />
                  <button class="mag-ability" @click="toggle(ability.name)">
                    <span class="mag-ability__ns">{{ ability.namespace }}/</span
                    ><span class="mag-ability__slug">{{ ability.name.split('/')[1] }}</span>
                  </button>
                </div>
                <div class="mag-meta" style="margin-top: 4px">{{ ability.label }}</div>
              </td>

              <td>
                <div v-if="ability.profile.operations.length" class="mag-ops">
                  <span
                    v-for="op in ability.profile.operations.slice(0, 3)"
                    :key="op"
                    class="mag-op"
                    :class="{ 'mag-op--irreversible': ability.irreversible.includes(op) }"
                    :title="ability.irreversible.includes(op) ? 'Cannot be undone' : 'Can be undone'"
                  >{{ op }}</span>
                  <span v-if="ability.profile.operations.length > 3" class="mag-op">
                    +{{ ability.profile.operations.length - 3 }}
                  </span>
                </div>
                <span v-else class="mag-empty-cell">—</span>
              </td>

              <td>
                <div class="mag-rule">
                  <BaseSelect
                    :model-value="effective(ability)"
                    :options="options(ability)"
                    :disabled="!store.meta.canManage"
                    @update:model-value="onRule(ability, $event)"
                  />

                  <!-- The people who will be asked, shown only when someone
                       will actually be asked. -->
                  <UserPicker
                    v-if="!ability.isRead && effective(ability) === 'require'"
                    :model-value="draftFor(ability).values"
                    :quorum="draftFor(ability).quorum"
                    :disabled="!store.meta.canManage"
                    @update:model-value="onApprovers(ability, $event)"
                    @update:quorum="onQuorum(ability, $event)"
                  />
                </div>
              </td>
            </tr>

            <tr v-if="expanded === ability.name">
              <td colspan="3" style="padding: 0">
                <div class="mag-drawer">
                  <div>
                    <p class="mag-section">What it says about itself</p>
                    <p class="mag-meta" style="margin: 0 0 14px">
                      {{ ability.description || 'No description provided.' }}
                    </p>

                    <div class="mag-row" style="margin-bottom: 18px">
                      <span class="mag-tag mag-tag--mute">
                        readonly: {{ ability.declared.readonly === null ? 'undeclared' : ability.declared.readonly }}
                      </span>
                      <span class="mag-tag mag-tag--mute">
                        destructive: {{ ability.declared.destructive === null ? 'undeclared' : ability.declared.destructive }}
                      </span>
                      <span class="mag-tag mag-tag--mute">schema: {{ ability.schema.score }}</span>
                    </div>

                    <p class="mag-section">Why this decision</p>
                    <p class="mag-meta" style="margin: 0 0 14px">
                      {{ ability.reason }}
                      <template v-if="ability.isRead">
                        Approval is not offered for reads: the queue returns a status to the
                        agent, never the data it asked for.
                      </template>
                    </p>

                    <p class="mag-section">If it goes wrong</p>
                    <p v-if="!ability.profile.operations.length" class="mag-meta" style="margin: 0">
                      Unknown until it runs once.
                    </p>
                    <p v-else-if="ability.reversible" class="mag-meta" style="margin: 0">
                      Everything it has done could be undone from the audit log.
                    </p>
                    <p v-else class="mag-meta" style="margin: 0">
                      Not fully reversible — {{ ability.irreversible.join(', ') }}.
                      <template v-if="ability.profile.tables.length">
                        Writes to {{ ability.profile.tables.join(', ') }}.
                      </template>
                    </p>
                  </div>

                  <div>
                    <p class="mag-section">Activity</p>
                    <div class="mag-stack">
                      <span class="mag-meta">
                        {{ ability.profile.observations || 'No' }}
                        {{ ability.profile.observations === 1 ? 'run' : 'runs' }} recorded
                      </span>
                      <span class="mag-meta mag-meta--dim">
                        confidence: {{ ability.profile.confidence }}
                      </span>
                      <span v-if="ability.profile.lastSeen" class="mag-meta mag-meta--dim">
                        last seen {{ ability.profile.lastSeen }}
                      </span>
                    </div>

                    <template v-if="effective(ability) === 'require'">
                      <p class="mag-section" style="margin-top: 18px">Approvers</p>
                      <div class="mag-stack">
                        <span
                          v-for="person in ability.approvers.resolved"
                          :key="person.id"
                          class="mag-meta"
                        >{{ person.name }}</span>
                        <span class="mag-meta mag-meta--dim">
                          {{ ability.approvers.quorum === 'all' ? 'all must approve' : 'any one approves' }}
                        </span>
                      </div>
                    </template>
                  </div>
                </div>
              </td>
            </tr>
          </template>

          <tr v-if="!visible.length">
            <td colspan="3">
              <div class="mag-empty">
                <div class="mag-empty__title">Nothing matches</div>
                <div>Try a different filter.</div>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
