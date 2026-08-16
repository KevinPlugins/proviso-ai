<script setup>
import { ref } from 'vue';
import { store, decideRequest } from '../store';
import KindTag from '../components/KindTag.vue';

const comments = ref({});
const busy = ref(null);

const tierLabels = {
  diff: 'Verified diff against current state',
  declared: 'Exact preview supplied by the ability',
  args: 'Arguments only — effect not verified',
};

async function decide(request, decision) {
  busy.value = `${request.id}:${decision}`;
  await decideRequest(request.id, decision, comments.value[request.id] || '');
  comments.value[request.id] = '';
  busy.value = null;
}

function truncate(value) {
  if (value === null || value === undefined) return '—';
  const text = typeof value === 'object' ? JSON.stringify(value) : String(value);
  return text.length > 600 ? `${text.slice(0, 600)}…` : text;
}
</script>

<template>
  <div>
    <h2 class="mag-title">Approval queue</h2>
    <p class="mag-lede">
      Changes an agent proposed that are waiting on a human. Nothing here has touched the
      site yet.
    </p>

    <div v-if="!store.requests.length" class="mag-panel">
      <div class="mag-empty">
        <div class="mag-empty__title">Nothing waiting</div>
        <div>Gated abilities will queue their changes here.</div>
      </div>
    </div>

    <article v-for="request in store.requests" :key="request.id" class="mag-card">
      <header class="mag-card__head">
        <div>
          <div class="mag-row" style="gap: 7px">
            <KindTag :kind="request.kind" basis="observed" />
            <span class="mag-mono">{{ request.ability }}</span>
            <span class="mag-meta mag-meta--dim">#{{ request.id }}</span>
          </div>
          <div class="mag-meta" style="margin-top: 5px">{{ request.summary }}</div>
        </div>

        <div class="mag-stack" style="text-align: right; flex: none">
          <span class="mag-meta">{{ request.requester.label }}</span>
          <span class="mag-meta mag-meta--dim">
            {{ request.requester.tier }} · as {{ request.requester.user }}
          </span>
          <span class="mag-meta mag-meta--dim">{{ request.createdAt }}</span>
        </div>
      </header>

      <div class="mag-card__body">
        <div class="mag-row" style="justify-content: space-between; margin-bottom: 10px">
          <span class="mag-section" style="margin: 0">
            {{ tierLabels[request.preview.tier] || request.preview.tier }}
          </span>
          <span
            v-if="request.expiresAt"
            class="mag-meta mag-meta--dim"
          >expires {{ request.expiresAt }}</span>
        </div>

        <div v-if="request.preview.notice" class="mag-note" style="margin-bottom: 12px">
          {{ request.preview.notice }}
        </div>

        <div v-if="Object.keys(request.preview.diff || {}).length" class="mag-diff">
          <div
            v-for="(change, field) in request.preview.diff"
            :key="field"
            class="mag-diff__row"
          >
            <div class="mag-diff__key">{{ field }}</div>
            <div class="mag-diff__vals">
              <div class="mag-diff__val mag-diff__val--before">{{ truncate(change.before) }}</div>
              <div class="mag-diff__val mag-diff__val--after">{{ truncate(change.after) }}</div>
            </div>
          </div>
        </div>

        <div v-else-if="request.preview.fields?.length" class="mag-diff">
          <div v-for="field in request.preview.fields" :key="field.key" class="mag-diff__row">
            <div class="mag-diff__key">
              {{ field.label }}
              <div v-if="field.description" class="mag-meta mag-meta--dim" style="font-family: inherit">
                {{ field.description }}
              </div>
            </div>
            <div class="mag-diff__val">{{ truncate(field.value) }}</div>
          </div>
        </div>

        <div v-if="request.approvals.votes.length" style="margin-top: 14px">
          <p class="mag-section">Decisions so far</p>
          <div
            v-for="(vote, index) in request.approvals.votes"
            :key="index"
            class="mag-meta"
          >
            {{ vote.user }} — {{ vote.decision }}<template v-if="vote.comment">: {{ vote.comment }}</template>
          </div>
        </div>
      </div>

      <footer class="mag-card__foot">
        <input
          v-model="comments[request.id]"
          class="mag-input"
          style="flex: 1; min-width: 180px"
          placeholder="Optional note"
        />

        <button
          class="mag-btn mag-btn--primary"
          :disabled="!request.canDecide || busy === `${request.id}:approve`"
          @click="decide(request, 'approve')"
        >
          {{ request.approvals.required > 1
            ? `Approve (${request.approvals.have}/${request.approvals.required})`
            : 'Approve and apply' }}
        </button>

        <button
          class="mag-btn mag-btn--danger"
          :disabled="!request.canDecide || busy === `${request.id}:reject`"
          @click="decide(request, 'reject')"
        >Reject</button>

        <span v-if="!request.canDecide" class="mag-meta mag-meta--dim">
          You are not an approver for this ability.
        </span>
        <span v-else-if="request.approvals.quorum === 'all'" class="mag-meta mag-meta--dim">
          All approvers must agree.
        </span>
      </footer>
    </article>
  </div>
</template>
