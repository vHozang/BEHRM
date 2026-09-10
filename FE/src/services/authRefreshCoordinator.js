const LOCK_NAME = 'hrm-auth-refresh-v1';
const CHANNEL_NAME = 'hrm-auth-session-v1';
const LEASE_KEY = 'hrm_auth_refresh_lease';
const LEASE_TTL_MS = 15_000;
const LEASE_HEARTBEAT_MS = 5_000;
const REFRESH_WAIT_MS = 6_000;

const tabId = globalThis.crypto?.randomUUID?.()
  || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
const signalWaiters = new Set();
const sessionClearedListeners = new Set();
let inTabRefreshPromise = null;

const storage = () => {
  try {
    return globalThis.localStorage || null;
  } catch {
    return null;
  }
};

const notifyWaiters = () => {
  for (const resolve of [...signalWaiters]) resolve();
};

const notifySessionCleared = () => {
  for (const listener of [...sessionClearedListeners]) listener();
};

const channel = typeof window !== 'undefined' && typeof globalThis.BroadcastChannel === 'function'
  ? new globalThis.BroadcastChannel(CHANNEL_NAME)
  : null;

if (channel) {
  channel.addEventListener('message', (event) => {
    if (event.data?.type === 'SESSION_CLEARED') notifySessionCleared();
    if (['TOKEN_REFRESHED', 'LEASE_RELEASED'].includes(event.data?.type)) notifyWaiters();
  });
}

if (typeof globalThis.addEventListener === 'function') {
  globalThis.addEventListener('storage', (event) => {
    if (['auth_token', 'auth_expires_at', LEASE_KEY].includes(event.key)) notifyWaiters();
  });
}

const broadcast = (message) => {
  channel?.postMessage({ ...message, source: tabId, at: Date.now() });
  notifyWaiters();
};

const waitForSignal = (timeoutMs) => new Promise((resolve) => {
  let timer;
  const done = () => {
    clearTimeout(timer);
    signalWaiters.delete(done);
    resolve();
  };
  timer = setTimeout(done, Math.max(1, timeoutMs));
  signalWaiters.add(done);
});

const parseLease = () => {
  try {
    const raw = storage()?.getItem(LEASE_KEY);
    const parsed = raw ? JSON.parse(raw) : null;
    return parsed && typeof parsed.owner === 'string' && Number.isFinite(parsed.expires_at)
      ? parsed
      : null;
  } catch {
    return null;
  }
};

const writeLease = (lease) => {
  try {
    storage()?.setItem(LEASE_KEY, JSON.stringify(lease));
    return true;
  } catch {
    return false;
  }
};

const releaseLease = () => {
  try {
    if (parseLease()?.owner === tabId) storage()?.removeItem(LEASE_KEY);
  } finally {
    broadcast({ type: 'LEASE_RELEASED' });
  }
};

const withStorageLease = async (work) => {
  if (!storage()) return work();

  while (true) {
    const current = parseLease();
    if (!current || current.expires_at <= Date.now() || current.owner === tabId) {
      const mine = { owner: tabId, expires_at: Date.now() + LEASE_TTL_MS };
      if (!writeLease(mine)) return work();

      // localStorage has no atomic compare-and-set. A short settle period lets
      // concurrent tabs observe which owner won before any request is sent.
      await new Promise((resolve) => setTimeout(resolve, 25 + Math.floor(Math.random() * 25)));
      if (parseLease()?.owner === tabId) {
        const heartbeat = setInterval(() => {
          if (parseLease()?.owner === tabId) {
            writeLease({ owner: tabId, expires_at: Date.now() + LEASE_TTL_MS });
          }
        }, LEASE_HEARTBEAT_MS);
        try {
          return await work();
        } finally {
          clearInterval(heartbeat);
          releaseLease();
        }
      }
    }

    const lease = parseLease();
    const waitMs = lease
      ? Math.min(1_000, Math.max(50, lease.expires_at - Date.now() + 25))
      : 50;
    await waitForSignal(waitMs);
  }
};

const withCrossTabLock = (work) => {
  const locks = globalThis.navigator?.locks;
  if (locks?.request) return locks.request(LOCK_NAME, { mode: 'exclusive' }, work);
  return withStorageLease(work);
};

const waitForFreshToken = async (readToken, initialToken, isTokenFresh) => {
  const deadline = Date.now() + REFRESH_WAIT_MS;
  while (Date.now() < deadline) {
    const current = readToken();
    if (current && current !== initialToken && isTokenFresh()) return current;
    await waitForSignal(Math.min(250, deadline - Date.now()));
  }
  return null;
};

const isRefreshInProgress = (error) => error?.response?.status === 409
  && error?.response?.data?.code === 'REFRESH_IN_PROGRESS';

export const coordinateRefresh = ({ readToken, isTokenFresh, performRefresh }) => {
  if (inTabRefreshPromise) return inTabRefreshPromise;

  const initialToken = readToken();
  inTabRefreshPromise = withCrossTabLock(async () => {
    const currentToken = readToken();
    if (currentToken && currentToken !== initialToken && isTokenFresh()) return currentToken;

    const refreshAndNotify = async () => {
      const token = await performRefresh();
      broadcast({ type: 'TOKEN_REFRESHED' });
      return token;
    };

    try {
      return await refreshAndNotify();
    } catch (error) {
      if (!isRefreshInProgress(error)) throw error;

      const refreshedByAnotherTab = await waitForFreshToken(readToken, initialToken, isTokenFresh);
      if (refreshedByAnotherTab) return refreshedByAnotherTab;

      // The winning tab may have rotated the shared HttpOnly cookie but closed
      // before persisting its access token. Retry once with the new cookie.
      return refreshAndNotify();
    }
  }).finally(() => {
    inTabRefreshPromise = null;
  });

  return inTabRefreshPromise;
};

export const broadcastSessionCleared = () => broadcast({ type: 'SESSION_CLEARED' });

export const subscribeSessionCleared = (listener) => {
  sessionClearedListeners.add(listener);
  return () => sessionClearedListeners.delete(listener);
};
