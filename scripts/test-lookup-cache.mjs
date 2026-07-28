import assert from 'node:assert/strict';
import { createEmployeeLookupCache } from '../client/src/services/employeeLookupCache.js';

const cache = createEmployeeLookupCache(60_000);
let calls = 0;
const load = async () => {
  calls += 1;
  return [{ id: calls }];
};

const [first, concurrent] = await Promise.all([cache.get(load), cache.get(load)]);
assert.equal(calls, 1, 'concurrent callers must share one request');
assert.deepEqual(first, concurrent);
assert.deepEqual(await cache.get(load), first, 'fresh value must be reused');

cache.invalidate();
assert.deepEqual(await cache.get(load), [{ id: 2 }], 'mutation must invalidate the cache');
assert.equal(calls, 2);

console.log('employee lookup cache: ok');
