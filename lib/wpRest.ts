// Core WordPress REST API v2 access — URL normalization + authentication.
// Unlike lib/wpPlugin.ts (EkoSEO Bridge namespace), this talks to the standard
// /wp-json/wp/v2 endpoints, which every WordPress site exposes.

export interface WpAuthSite {
  domain: string;
  wpApiUrl?: string | null;
  wpAuthMethod?: string | null; // "jwt" | "app-password" | "legacy"
  wpJwtToken?: string | null;
  wpAppUser?: string | null;
  wpAppPassword?: string | null;
  wpAuthToken?: string | null; // legacy JUPITER key or "user:pass"
}

const BRIDGE_BOT = "ekoseo_bot"; // JUPITER keys are application passwords on this account

/** Root of the WP REST API, e.g. "https://site.com/wp-json". */
export function wpJsonRoot(site: WpAuthSite): string {
  let base = site.wpApiUrl?.trim() ? site.wpApiUrl.trim() : `https://${site.domain}`;
  base = base.replace(/\/+$/, "");
  base = base.replace(/\/wp\/v2$/, "");
  if (!/\/wp-json$/.test(base)) base = `${base}/wp-json`;
  return base;
}

/** Base of the core v2 namespace, e.g. "https://site.com/wp-json/wp/v2". */
export function wpV2Base(site: WpAuthSite): string {
  return `${wpJsonRoot(site)}/wp/v2`;
}

function basic(pair: string): string {
  return `Basic ${Buffer.from(pair).toString("base64")}`;
}

/**
 * Authorization headers for the site, best credential first:
 * 1. Application Password (Basic) — includes the spaces WP puts in them.
 * 2. JWT token (Bearer).
 * 3. Legacy wpAuthToken: "user:pass" → Basic; JWT-shaped → Bearer; bare → JUPITER key
 *    (Basic on the ekoseo_bot account, same convention as lib/wpPlugin.ts).
 * Returns headers without Authorization when the site has no credentials at all
 * (public read-only sync still works; export will fail with a clear error).
 */
export function buildAuthHeaders(site: WpAuthSite): Record<string, string> {
  const headers: Record<string, string> = { "Content-Type": "application/json" };
  const auth = authorizationValue(site);
  if (auth) headers.Authorization = auth;
  return headers;
}

function authorizationValue(site: WpAuthSite): string | null {
  if (site.wpAuthMethod === "app-password" && site.wpAppUser && site.wpAppPassword) {
    return basic(`${site.wpAppUser}:${site.wpAppPassword}`);
  }
  if (site.wpAuthMethod === "jwt" && site.wpJwtToken?.trim()) {
    return `Bearer ${site.wpJwtToken.trim()}`;
  }
  const legacy = site.wpAuthToken?.trim();
  if (legacy) {
    if (legacy.includes(":")) return basic(legacy);
    if (legacy.split(".").length === 3) return `Bearer ${legacy}`; // JWT shape
    return basic(`${BRIDGE_BOT}:${legacy}`);
  }
  // Fall through for sites where the method fields are set but empty.
  if (site.wpAppUser && site.wpAppPassword) return basic(`${site.wpAppUser}:${site.wpAppPassword}`);
  if (site.wpJwtToken?.trim()) return `Bearer ${site.wpJwtToken.trim()}`;
  return null;
}

export function hasWpCredentials(site: WpAuthSite): boolean {
  return authorizationValue(site) !== null;
}

/**
 * True when the site authenticates with a bare EkoSEO Bridge JUPITER key —
 * an application password on the ekoseo_bot account whose custom role has NO
 * core wp/v2 edit capabilities. Reads still work; writes must go through the
 * bridge namespace (lib/wpPlugin.ts).
 */
export function isJupiterKey(site: WpAuthSite): boolean {
  if (site.wpAuthMethod === "app-password" && site.wpAppUser && site.wpAppPassword) return false;
  if (site.wpAuthMethod === "jwt" && site.wpJwtToken?.trim()) return false;
  const legacy = site.wpAuthToken?.trim();
  return !!legacy && !legacy.includes(":") && legacy.split(".").length !== 3;
}

/** fetch with sane defaults for WP REST calls (30 s timeout, no cache). */
export function wpFetch(url: string, init?: RequestInit): Promise<Response> {
  return fetch(url, {
    cache: "no-store",
    signal: AbortSignal.timeout(30_000),
    ...init,
  });
}

/** REST base fallback when a page predates restBase storage. */
export function getRestBase(wpType: string, stored?: string | null): string {
  if (stored) return stored;
  const map: Record<string, string> = { page: "pages", post: "posts" };
  return map[wpType] || `${wpType}s`;
}

export interface WpTypeInfo {
  slug: string;
  restBase: string;
  name: string;
}

export interface WpConnectionTest {
  ok: boolean;
  authenticated: boolean;
  via: "core" | "bridge" | null; // which channel grants write access
  bridgeVersion: string | null;
  siteName: string | null;
  wpUrl: string;
  postTypes: string[];
  pagesCount: number | null;
  mediaCount: number | null;
  error: string | null;
}

/**
 * Probe a WordPress site: reachability, whether the credentials grant edit
 * rights (context=edit needs them), registered post types and library size.
 */
export async function testWpConnection(site: WpAuthSite): Promise<WpConnectionTest> {
  const root = wpJsonRoot(site);
  const v2 = wpV2Base(site);
  const headers = buildAuthHeaders(site);
  const result: WpConnectionTest = {
    ok: false,
    authenticated: false,
    via: null,
    bridgeVersion: null,
    siteName: null,
    wpUrl: root,
    postTypes: [],
    pagesCount: null,
    mediaCount: null,
    error: null,
  };

  try {
    const rootRes = await wpFetch(root, { headers });
    if (rootRes.ok) {
      const info = (await rootRes.json().catch(() => null)) as { name?: string } | null;
      result.siteName = info?.name || null;
    }

    // context=edit only answers 200 with credentials that can edit content.
    const editRes = await wpFetch(`${v2}/pages?per_page=1&context=edit`, { headers });
    if (editRes.ok) {
      result.ok = true;
      result.authenticated = true;
      result.via = "core";
      result.pagesCount = parseInt(editRes.headers.get("X-WP-Total") || "0", 10) || null;
    } else {
      const readRes = await wpFetch(`${v2}/pages?per_page=1`, { headers });
      if (readRes.ok) {
        result.ok = true; // reachable — maybe read-only, maybe bridge-writable
        result.pagesCount = parseInt(readRes.headers.get("X-WP-Total") || "0", 10) || null;
        const detail = (await editRes.json().catch(() => null)) as { message?: string } | null;
        result.error = detail?.message || `Auth check failed (HTTP ${editRes.status}) — read-only access.`;
      } else {
        result.error = `WP REST API unreachable (HTTP ${readRes.status}).`;
        return result;
      }

      // JUPITER keys have no core edit caps by design — writes go through the
      // EkoSEO Bridge namespace instead. If the bridge answers, that IS write access.
      if (isJupiterKey(site)) {
        const { pingPlugin } = await import("./wpPlugin");
        const bridge = await pingPlugin({
          domain: site.domain,
          wpApiUrl: site.wpApiUrl,
          wpAuthToken: site.wpAuthToken,
        });
        if (bridge) {
          result.authenticated = true;
          result.via = "bridge";
          result.bridgeVersion = bridge.plugin_version || null;
          result.error = null;
          if (result.mediaCount === null && bridge.image_count) result.mediaCount = bridge.image_count;
        }
      }
    }

    const mediaRes = await wpFetch(`${v2}/media?per_page=1&media_type=image`, { headers });
    if (mediaRes.ok) result.mediaCount = parseInt(mediaRes.headers.get("X-WP-Total") || "0", 10);

    result.postTypes = (await discoverPostTypes(site)).map((t) => t.slug);
    return result;
  } catch (err) {
    result.error = (err as Error).message;
    return result;
  }
}

const SKIPPED_TYPES = new Set([
  "attachment",
  "wp_block",
  "nav_menu_item",
  "wp_navigation",
  "wp_template",
  "wp_template_part",
  "wp_global_styles",
  "wp_font_family",
  "wp_font_face",
]);

/**
 * Viewable post types registered on the site (pages, posts, public CPTs).
 * Falls back to page/post when /types is blocked. Types outside the wp/v2
 * namespace are skipped — we could read them but not safely write them.
 */
export async function discoverPostTypes(site: WpAuthSite): Promise<WpTypeInfo[]> {
  const fallback: WpTypeInfo[] = [
    { slug: "page", restBase: "pages", name: "Pages" },
    { slug: "post", restBase: "posts", name: "Posts" },
  ];
  try {
    const res = await wpFetch(`${wpV2Base(site)}/types`, { headers: buildAuthHeaders(site) });
    if (!res.ok) return fallback;
    const types = (await res.json()) as Record<
      string,
      { slug?: string; rest_base?: string; rest_namespace?: string; viewable?: boolean; name?: string }
    >;
    const list: WpTypeInfo[] = [];
    for (const [slug, t] of Object.entries(types)) {
      if (SKIPPED_TYPES.has(slug)) continue;
      if (t.viewable === false) continue;
      if (t.rest_namespace && t.rest_namespace !== "wp/v2") continue;
      list.push({ slug, restBase: t.rest_base || getRestBase(slug), name: t.name || slug });
    }
    return list.length ? list : fallback;
  } catch {
    return fallback;
  }
}
