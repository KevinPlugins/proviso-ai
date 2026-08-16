import { reactive } from 'vue';

const cfg = window.magGuard || {};

/**
 * Single reactive store. The app is small enough that Pinia would be ceremony;
 * one object with explicit actions keeps the data flow obvious.
 */
export const store = reactive({
  ready: false,
  loading: false,
  view: 'abilities',

  abilities: [],
  requests: [],
  audit: [],
  settings: {},
  meta: { roles: [], users: [], sharedAccounts: [], canManage: false, pending: 0 },

  toast: null,
});

let toastTimer = null;

export function notify(message, type = 'info') {
  store.toast = { message, type };
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (store.toast = null), type === 'error' ? 7000 : 3500);
}

async function api(path, options = {}) {
  const response = await fetch(`${cfg.root}mag/v1${path}`, {
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': cfg.nonce,
    },
    ...options,
    body: options.body ? JSON.stringify(options.body) : undefined,
  });

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error(payload?.message || `Request failed (${response.status})`);
  }

  return payload;
}

function absorb(data) {
  if (data.abilities) store.abilities = data.abilities;
  if (data.requests) store.requests = data.requests;
  if (data.audit) store.audit = data.audit;
  if (data.settings) store.settings = data.settings;
  if (data.meta) store.meta = data.meta;
}

export async function load() {
  store.loading = true;
  try {
    absorb(await api('/bootstrap'));
    store.ready = true;
  } catch (error) {
    notify(error.message, 'error');
  } finally {
    store.loading = false;
  }
}

export async function refreshAbilities() {
  try {
    store.abilities = await api('/abilities');
  } catch (error) {
    notify(error.message, 'error');
  }
}

export async function saveRule(ability, changes) {
  try {
    const data = await api('/abilities/rule', {
      method: 'POST',
      body: { ability, ...changes },
    });

    const index = store.abilities.findIndex((a) => a.name === ability);
    if (index !== -1 && data.ability) store.abilities[index] = data.ability;

    notify('Saved');
  } catch (error) {
    notify(error.message, 'error');
  }
}

export async function decideRequest(id, decision, comment = '') {
  try {
    const data = await api(`/requests/${id}/decide`, {
      method: 'POST',
      body: { decision, comment },
    });

    absorb(data);
    store.meta.pending = store.requests.length;

    // An ALL quorum reports progress instead of completion.
    notify(data.result?.message || (decision === 'approve' ? 'Approved and applied' : 'Rejected'));
  } catch (error) {
    notify(error.message, 'error');
  }
}

export async function undoEntry(id) {
  try {
    const data = await api(`/audit/${id}/undo`, { method: 'POST' });
    absorb(data);

    const { applied = 0, skipped = [] } = data.result || {};
    if (applied === 0 && skipped.length) {
      notify(skipped[0], 'error');
    } else {
      notify(`Reverted ${applied} change${applied === 1 ? '' : 's'}`);
    }
  } catch (error) {
    notify(error.message, 'error');
  }
}

export async function saveSettings(changes) {
  try {
    absorb(await api('/settings', { method: 'POST', body: changes }));
    notify('Settings saved');
  } catch (error) {
    notify(error.message, 'error');
  }
}

export { cfg };
