import { expect, test } from '@playwright/test';

const futureExpiry = () => new Date(Date.now() + 60 * 60 * 1000).toISOString();

const openAuthTabs = async (browser, count = 5, { disableWebLocks = false } = {}) => {
  const context = await browser.newContext();
  if (disableWebLocks) {
    await context.addInitScript(() => {
      try {
        Object.defineProperty(navigator, 'locks', { value: undefined, configurable: true });
      } catch {
        // The fallback test will fail on request count if Web Locks remained active.
      }
    });
  }
  const pages = await Promise.all(Array.from({ length: count }, async () => {
    const page = await context.newPage();
    await page.goto('/login');
    return page;
  }));

  const initialize = (page, expiresAt) => page.evaluate(async ({ expiresAt: expiry }) => {
    localStorage.setItem('auth_token', 'old-token');
    localStorage.setItem('auth_expires_at', expiry);
    const auth = await import('/src/services/axiosClient.js');
    window.__authRefreshTest = {
      refresh: () => auth.refreshAccessToken(),
      request: (url) => auth.default.get(url).then((response) => response.data),
    };
  }, { expiresAt });

  return { context, pages, initialize };
};

test('five tabs share one proactive refresh request with Web Locks', async ({ browser }) => {
  const { context, pages, initialize } = await openAuthTabs(browser);
  let refreshRequests = 0;
  await context.route('**/api/v1/**', async (route) => {
    if (new URL(route.request().url()).pathname.endsWith('/auth/refresh')) {
      refreshRequests++;
      await new Promise((resolve) => setTimeout(resolve, 200));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 200,
          data: { access_token: 'fresh-token', expires_at: futureExpiry() },
        }),
      });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: '{"status":200,"data":{}}' });
  });

  await Promise.all(pages.map((page) => initialize(page, new Date(Date.now() + 60_000).toISOString())));
  const tokens = await Promise.all(pages.map((page) => page.evaluate(() => window.__authRefreshTest.refresh())));

  expect(refreshRequests).toBe(1);
  expect(new Set(tokens)).toEqual(new Set(['fresh-token']));
  await expect.poll(() => pages[4].evaluate(() => localStorage.getItem('auth_token'))).toBe('fresh-token');
  await context.close();
});

test('five simultaneous 401 responses refresh once and retry each request once', async ({ browser }) => {
  const { context, pages, initialize } = await openAuthTabs(browser);
  let refreshRequests = 0;
  let oldProtectedRequests = 0;
  let retriedProtectedRequests = 0;

  await context.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path.endsWith('/auth/refresh')) {
      refreshRequests++;
      await new Promise((resolve) => setTimeout(resolve, 150));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 200,
          data: { access_token: 'fresh-after-401', expires_at: futureExpiry() },
        }),
      });
      return;
    }
    if (path.endsWith('/test-protected')) {
      if (request.headers().authorization === 'Bearer fresh-after-401') {
        retriedProtectedRequests++;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: '{"status":200,"data":{"ok":true}}',
        });
      } else {
        oldProtectedRequests++;
        await new Promise((resolve) => setTimeout(resolve, 100));
        await route.fulfill({
          status: 401,
          contentType: 'application/json',
          body: '{"status":401,"message":"Expired access token","data":null}',
        });
      }
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: '{"status":200,"data":{}}' });
  });

  await Promise.all(pages.map((page) => initialize(page, futureExpiry())));
  const responses = await Promise.all(pages.map((page) => (
    page.evaluate(() => window.__authRefreshTest.request('/test-protected'))
  )));

  expect(responses).toEqual(Array.from({ length: 5 }, () => ({ ok: true })));
  expect(oldProtectedRequests).toBe(5);
  expect(retriedProtectedRequests).toBe(5);
  expect(refreshRequests).toBe(1);
  await context.close();
});

test('BroadcastChannel and localStorage lease coordinate tabs without Web Locks', async ({ browser }) => {
  const { context, pages, initialize } = await openAuthTabs(browser, 5, { disableWebLocks: true });
  let refreshRequests = 0;
  await context.route('**/api/v1/**', async (route) => {
    if (new URL(route.request().url()).pathname.endsWith('/auth/refresh')) {
      refreshRequests++;
      await new Promise((resolve) => setTimeout(resolve, 200));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 200,
          data: { access_token: 'fresh-fallback', expires_at: futureExpiry() },
        }),
      });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: '{"status":200,"data":{}}' });
  });

  await Promise.all(pages.map((page) => initialize(page, new Date(Date.now() + 60_000).toISOString())));
  const tokens = await Promise.all(pages.map((page) => page.evaluate(() => window.__authRefreshTest.refresh())));

  expect(refreshRequests).toBe(1);
  expect(new Set(tokens)).toEqual(new Set(['fresh-fallback']));
  await context.close();
});

test('409 REFRESH_IN_PROGRESS waits for another tab and does not clear the session', async ({ browser }) => {
  const { context, pages, initialize } = await openAuthTabs(browser, 2);
  let refreshRequests = 0;
  await context.route('**/api/v1/**', async (route) => {
    if (new URL(route.request().url()).pathname.endsWith('/auth/refresh')) {
      refreshRequests++;
      await route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: '{"status":409,"code":"REFRESH_IN_PROGRESS","data":{"retry_after_ms":500}}',
      });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: '{"status":200,"data":{}}' });
  });

  await Promise.all(pages.map((page) => initialize(page, new Date(Date.now() + 60_000).toISOString())));
  await pages[0].evaluate(() => {
    window.__pendingRefresh = window.__authRefreshTest.refresh();
  });
  await expect.poll(() => refreshRequests).toBe(1);
  await pages[1].evaluate((expiresAt) => {
    localStorage.setItem('auth_token', 'fresh-from-other-tab');
    localStorage.setItem('auth_expires_at', expiresAt);
  }, futureExpiry());

  await expect(pages[0].evaluate(() => window.__pendingRefresh)).resolves.toBe('fresh-from-other-tab');
  await expect.poll(() => pages[0].evaluate(() => localStorage.getItem('auth_token'))).toBe('fresh-from-other-tab');
  expect(pages[0].url()).toContain('/login');
  expect(refreshRequests).toBe(1);
  await context.close();
});
