import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { dirname, extname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const backend = join(root, 'Doan2_v2', 'Doan2');
const manifestPath = join(root, 'docs', 'endpoint-manifest.json');
const statuses = ['UI_DIRECT', 'UI_INDIRECT', 'INTERNAL', 'DEPRECATED', 'BACKEND_ONLY'];

const walk = (directory) => readdirSync(directory).flatMap((name) => {
  const path = join(directory, name);
  return statSync(path).isDirectory() ? walk(path) : [path];
});

const routeList = () => {
  const parse = (output) => {
    const start = output.indexOf('[');
    if (start < 0) throw new Error('route:list did not return JSON');
    return JSON.parse(output.slice(start));
  };
  try {
    return parse(execFileSync('php', ['artisan', 'route:list', '--json'], {
      cwd: backend,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    }));
  } catch (error) {
    try {
      return parse(execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', 'artisan', 'route:list', '--json'], {
        cwd: backend,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
      }));
    } catch {
      throw new Error(`Unable to read Laravel routes: ${error.message}`);
    }
  }
};

const normalizeRoutePath = (uri) => `/${String(uri || '').replace(/^\/+|\/+$/g, '')}`.replace(/\/$/, '') || '/';
const routeEntries = () => {
  const entries = [];
  for (const route of routeList()) {
    for (const method of String(route.method || '').split('|').filter((value) => value && value !== 'HEAD')) {
      entries.push({
        method: method.toUpperCase(),
        path: normalizeRoutePath(route.uri),
        action: route.action || null,
      });
    }
  }
  const unique = new Map(entries.map((entry) => [`${entry.method} ${entry.path}`, entry]));
  return [...unique.values()].sort((a, b) => `${a.path} ${a.method}`.localeCompare(`${b.path} ${b.method}`));
};

const normalizeFrontendPath = (value) => {
  let path = String(value || '').trim();
  if (!path.startsWith('/')) return null;
  path = path.replace(/\$\{[^}]+\}/g, '{param}').split('?')[0].split('#')[0];
  path = path.replace(/\/+/g, '/').replace(/\/$/, '') || '/';
  return path.startsWith('/api/v1') ? path : `/api/v1${path}`;
};

const frontendCalls = () => {
  const files = walk(join(root, 'client', 'src')).filter((path) => ['.js', '.vue', '.mjs'].includes(extname(path)));
  const calls = [];
  const usedResources = new Set();
  for (const file of files) {
    const source = readFileSync(file, 'utf8');
    const label = relative(root, file).replaceAll('\\', '/');
    const literal = /\b(?:axiosClient|refreshClient)\.(get|post|put|patch|delete)\(\s*(['"`])([\s\S]*?)\2/g;
    for (const match of source.matchAll(literal)) {
      const path = normalizeFrontendPath(match[3]);
      if (path) calls.push({ method: match[1].toUpperCase(), path, file: label });
    }
    const annotation = /endpoint-audit:\s*(GET|POST|PUT|PATCH|DELETE)\s+(\/[^\s*]+)/g;
    for (const match of source.matchAll(annotation)) {
      const path = normalizeFrontendPath(match[2]);
      if (path) calls.push({ method: match[1], path, file: label });
    }
    for (const match of source.matchAll(/\bresource\s*=\s*['"]([a-z0-9-]+)['"]/g)) {
      usedResources.add(match[1]);
    }
  }
  for (const call of calls) {
    const segments = call.path.replace(/^\/api\/v1\/?/, '').split('/');
    if (segments[0] && !segments[0].startsWith('{')) usedResources.add(segments[0]);
  }
  const unique = new Map(calls.map((call) => [`${call.method} ${call.path} ${call.file}`, call]));
  return { calls: [...unique.values()], usedResources };
};

const routeMatches = (routePath, callPath) => {
  const routeSegments = routePath.split('/').filter(Boolean);
  const callSegments = callPath.split('/').filter(Boolean);
  if (routeSegments.length !== callSegments.length) return false;
  return routeSegments.every((segment, index) => /^\{[^}]+\}$/.test(segment) || segment === callSegments[index]);
};

const parseGenericResources = () => {
  const source = readFileSync(join(backend, 'app', 'Support', 'HrmTables.php'), 'utf8');
  return [...source.matchAll(/^\s*'([a-z0-9-]+)'\s*=>\s*'[a-z0-9_]+'/gm)]
    .map((match) => match[1])
    .sort();
};

const pathWithoutPrefix = (path) => path.replace(/^\/api\/v1/, '') || '/';
const isCalled = (route, calls) => calls.some((call) => call.method === route.method && routeMatches(route.path, call.path));

const classifyRoute = (route, calls) => {
  const path = pathWithoutPrefix(route.path);
  if (!route.path.startsWith('/api/v1')) return 'INTERNAL';
  if (/^\/internal\//.test(path) || path === '/health' || path === '/' || /^\/platform\//.test(path)) return 'INTERNAL';
  if (/^\/(auth\/hierarchy|employees\/org-chart)(\/|$)/.test(path)) return 'DEPRECATED';
  if (/^\/(attendance\/realtime\/auth|auth\/refresh)(\/|$)/.test(path)) return 'UI_INDIRECT';
  if (/\/(download|exports?|attachments?|certificate|payslip\/pdf|payslips\/archive)(\/|$)/.test(path)) return 'UI_INDIRECT';
  if (/^\/(salary-breakdowns|salary-attendance-summary|dashboard-views)(\/|$)/.test(path)) return 'BACKEND_ONLY';
  if (/^\/salary-details(\/\{id\})?$/.test(path) && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(route.method)) return 'BACKEND_ONLY';
  if (path.includes('{resource}')) return 'BACKEND_ONLY';
  return isCalled(route, calls) ? 'UI_DIRECT' : 'BACKEND_ONLY';
};

const classifyResource = (resource, usedResources) => {
  if (['approval-roles', 'contract-histories', 'shift-schedule-details', 'shift-schedules'].includes(resource)) return 'DEPRECATED';
  if (['dashboard-views'].includes(resource)) return 'INTERNAL';
  if (['salary-attendance-summary', 'salary-breakdowns', 'report-histories', 'approval-histories'].includes(resource)) return 'BACKEND_ONLY';
  if (['certificates', 'leave-balances', 'news-reads', 'notification-configs', 'policy-acknowledgments', 'request-attachments'].includes(resource)) return 'UI_INDIRECT';
  return usedResources.has(resource) ? 'UI_DIRECT' : 'BACKEND_ONLY';
};

const generateManifest = (routes, calls, usedResources, resources) => ({
  version: 1,
  statuses: {
    UI_DIRECT: 'Được màn hình hoặc service frontend gọi trực tiếp.',
    UI_INDIRECT: 'Được dùng qua interceptor, upload/download, realtime hoặc workflow chuyên biệt.',
    INTERNAL: 'Endpoint vận hành, bridge, worker, telemetry hoặc hạ tầng.',
    DEPRECATED: 'Contract cũ được giữ để tương thích nhưng không dùng cho UI mới.',
    BACKEND_ONLY: 'Dữ liệu engine hoặc API chủ ý chưa có thao tác UI trực tiếp.',
  },
  routes: routes.map((route) => ({ ...route, status: classifyRoute(route, calls) })),
  generic_resources: Object.fromEntries(resources.map((resource) => [resource, classifyResource(resource, usedResources)])),
});

const audit = (manifest, routes, calls, usedResources, resources) => {
  const errors = [];
  const actual = new Set(routes.map((route) => `${route.method} ${route.path}`));
  const classified = new Set();
  for (const route of manifest.routes || []) {
    const key = `${route.method} ${route.path}`;
    if (!statuses.includes(route.status)) errors.push(`Invalid status for ${key}: ${route.status}`);
    if (classified.has(key)) errors.push(`Duplicate manifest route: ${key}`);
    classified.add(key);
  }
  for (const key of actual) if (!classified.has(key)) errors.push(`Laravel route is not classified: ${key}`);
  for (const key of classified) if (!actual.has(key)) errors.push(`Manifest route no longer exists: ${key}`);

  const generic = manifest.generic_resources || {};
  for (const resource of resources) {
    if (!statuses.includes(generic[resource])) errors.push(`Generic resource is not classified: ${resource}`);
  }
  for (const resource of Object.keys(generic)) {
    if (!resources.includes(resource)) errors.push(`Manifest generic resource no longer exists: ${resource}`);
    if (generic[resource] === 'UI_DIRECT' && !usedResources.has(resource)) {
      errors.push(`UI_DIRECT generic resource has no frontend caller: ${resource}`);
    }
  }

  const isGenericRoute = (route) => route.path.startsWith('/api/v1/{resource}')
    && String(route.action || '').includes('GenericResourceController');
  const concreteRoutes = routes.filter((route) => !isGenericRoute(route));
  const genericRoutes = routes.filter(isGenericRoute);
  for (const call of calls) {
    const direct = concreteRoutes.some((route) => route.method === call.method && routeMatches(route.path, call.path));
    const resource = call.path.replace(/^\/api\/v1\/?/, '').split('/')[0];
    const genericMatch = genericRoutes.some((route) => route.method === call.method && routeMatches(route.path, call.path))
      && (resource.startsWith('{') || Boolean(generic[resource]));
    if (!direct && !genericMatch) errors.push(`Frontend call has no Laravel route: ${call.method} ${call.path} (${call.file})`);
  }

  for (const route of manifest.routes || []) {
    if (route.status !== 'UI_DIRECT') continue;
    if (!calls.some((call) => call.method === route.method && routeMatches(route.path, call.path))) {
      errors.push(`UI_DIRECT route has no frontend caller: ${route.method} ${route.path}`);
    }
  }
  return errors;
};

const routes = routeEntries();
const { calls, usedResources } = frontendCalls();
const resources = parseGenericResources();

if (process.argv.includes('--write')) {
  const manifest = generateManifest(routes, calls, usedResources, resources);
  writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`);
  console.log(`Wrote ${relative(root, manifestPath)} with ${routes.length} routes and ${resources.length} generic resources.`);
  process.exit(0);
}

if (!existsSync(manifestPath)) {
  console.error(`Missing endpoint manifest: ${relative(root, manifestPath)}`);
  process.exit(1);
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const errors = audit(manifest, routes, calls, usedResources, resources);
if (errors.length) {
  console.error(`Endpoint contract audit failed (${errors.length} issue(s)):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Endpoint contract audit passed: ${routes.length} Laravel routes, ${calls.length} frontend calls, ${resources.length} generic resources.`);
