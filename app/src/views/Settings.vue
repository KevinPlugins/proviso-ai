<script setup>
import { reactive, watch } from 'vue';
import { store, saveSettings } from '../store';
import UserPicker from '../components/UserPicker.vue';
import BaseSelect from '../components/BaseSelect.vue';

const defaultOptions = [
  {
    value: 'auto',
    label: 'Allow it',
    dot: 'ok',
    hint: 'Runs, is recorded, and can be undone from the audit log.',
  },
  {
    value: 'require',
    label: 'Require approval',
    dot: 'write',
    hint: 'Nothing runs until a person approves it.',
  },
];

const form = reactive({
  learningMode: false,
  autoReadonly: true,
  trustDeclaredReadonly: true,
  gateUnresolved: true,
  timeoutMinutes: 0,
  defaultDecision: 'auto',
  approverValues: [],
  approverQuorum: 'any',
});

watch(
  () => store.settings,
  (settings) => {
    Object.assign(form, {
      learningMode: !!settings.learningMode,
      autoReadonly: !!settings.autoReadonly,
      trustDeclaredReadonly: !!settings.trustDeclaredReadonly,
      gateUnresolved: !!settings.gateUnresolved,
      timeoutMinutes: settings.timeoutMinutes ?? 0,
      defaultDecision: settings.defaultDecision || 'auto',
      approverValues: [...(settings.approverDefault?.values || [])].filter((v) => v.startsWith('user:')),
      approverQuorum: settings.approverDefault?.quorum || 'any',
    });
  },
  { immediate: true, deep: true }
);

function submit() {
  saveSettings({
    learning_mode: form.learningMode,
    auto_readonly: form.autoReadonly,
    trust_declared_readonly: form.trustDeclaredReadonly,
    gate_unresolved: form.gateUnresolved,
    timeout_minutes: Number(form.timeoutMinutes) || 0,
    default_decision: form.defaultDecision,
    approver_default: {
      values: form.approverValues,
      quorum: form.approverQuorum,
    },
  });
}
</script>

<template>
  <div style="max-width: 780px">
    <h2 class="mag-title">Settings</h2>
    <p class="mag-lede">
      Defaults applied to every ability that has no rule of its own.
    </p>

    <div v-if="form.learningMode" class="mag-note">
      <strong>Learning mode is on.</strong> Nothing is being gated — every call runs and is
      recorded so profiles can build. Turn this off once the log looks right.
    </div>

    <div class="mag-panel" style="padding: 4px 16px; margin-bottom: 20px">
      <label class="mag-check">
        <input v-model="form.learningMode" type="checkbox" />
        <span>
          <span class="mag-check__label">Learning mode</span>
          <div class="mag-check__hint">
            Run everything, gate nothing, and record what each ability does. Useful for a few
            days on a new install; unsafe as a permanent setting.
          </div>
        </span>
      </label>

      <label class="mag-check">
        <input v-model="form.autoReadonly" type="checkbox" />
        <span>
          <span class="mag-check__label">Allow abilities proven to only read</span>
          <div class="mag-check__hint">
            Once an ability has run repeatedly without writing anything, stop asking about it.
          </div>
        </span>
      </label>

      <label class="mag-check">
        <input v-model="form.trustDeclaredReadonly" type="checkbox" />
        <span>
          <span class="mag-check__label">Trust a read-only claim on first use</span>
          <div class="mag-check__hint">
            Lets an unproven ability that presents itself as read-only run once, under
            observation. If it writes, the claim is revoked immediately and logged. Without
            this, every new read is queued for approval it does not need.
          </div>
        </span>
      </label>

      <label class="mag-check">
        <input v-model="form.gateUnresolved" type="checkbox" />
        <span>
          <span class="mag-check__label">Require approval when the caller is unidentifiable</span>
          <div class="mag-check__hint">
            Some MCP plugins authenticate with a shared key and run every agent as the same
            administrator. When we cannot tell who is calling, treat it as the riskiest case.
          </div>
        </span>
      </label>
    </div>

    <p class="mag-section">Abilities with no rule of their own</p>
    <div class="mag-panel" style="padding: 16px; margin-bottom: 20px">
      <BaseSelect v-model="form.defaultDecision" :options="defaultOptions" />
      <div v-if="form.defaultDecision === 'auto'" class="mag-check__hint" style="margin-top: 10px">
        Every ability runs unless you gate or block it individually. You still get the full
        audit trail and one-click undo, but a write you have not thought about will go through.
      </div>
      <div v-else class="mag-check__hint" style="margin-top: 10px">
        Nothing runs until you allow it. Safer, and noisier — expect a queue to fill up on a
        site with many abilities.
      </div>
    </div>

    <p class="mag-section">Unanswered requests</p>
    <div class="mag-panel" style="padding: 16px; margin-bottom: 20px">
      <div class="mag-row">
        <input
          v-model.number="form.timeoutMinutes"
          class="mag-input"
          type="number"
          min="0"
          step="15"
          style="width: 110px"
        />
        <span class="mag-meta">minutes before a pending request expires. 0 keeps it forever.</span>
      </div>
      <div class="mag-check__hint" style="margin-top: 8px">
        An expired request is refused, never approved — silence is not consent.
      </div>
    </div>

    <p class="mag-section">Default approvers</p>
    <div class="mag-panel" style="padding: 16px; margin-bottom: 20px">
      <UserPicker v-model="form.approverValues" v-model:quorum="form.approverQuorum" />
      <div class="mag-check__hint" style="margin-top: 8px">
        Used wherever an ability has no approvers of its own. Leaving this empty falls back to
        administrators rather than to nobody.
      </div>
    </div>

    <button class="mag-btn mag-btn--primary" :disabled="!store.meta.canManage" @click="submit">
      Save settings
    </button>
  </div>
</template>
