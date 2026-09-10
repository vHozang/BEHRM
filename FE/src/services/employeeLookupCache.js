export const createEmployeeLookupCache = (ttlMs = 60_000) => {
  let value = null;
  let cachedAt = 0;
  let pending = null;

  return {
    get(loader, force = false) {
      if (!force && value && Date.now() - cachedAt < ttlMs) return Promise.resolve(value);
      if (!force && pending) return pending;

      pending = Promise.resolve().then(loader).then(result => {
        value = result;
        cachedAt = Date.now();
        return value;
      }).finally(() => { pending = null; });

      return pending;
    },
    invalidate() {
      value = null;
      cachedAt = 0;
    }
  };
};
