// Client for the WordPress connection.
// Primary protocol: EkoSEO Bridge (namespace ekoseo/v1) — the plugin already installed
// on the group's sites. Auth = WordPress application password on the ekoseo_bot
// account (the "clé JUPITER"), sent as HTTP Basic.
// Fallback: the standalone waronimage/v1 namespace (X-Waronimage-Key header).

export interface WpPluginSite {
  domain: string;
  wpApiUrl?: string | null;
  wpAuthToken?: string | null; // JUPITER application password (or "user:pass" override)
}

export interface WpPluginInfo {
  plugin: string;
  plugin_version: string;
  wp_version: string;
  site_name: string;
  site_url: string;
  image_count: number;
  post_types: string[];
}

export interface WpPluginImage {
  id: number;
  url: string;
  filename: string;
  mime_type: string;
  extension: string;
  width: number | null;
  height: number | null;
  filesize: number | null;
  alt: string;
  title: string;
  caption: string;
  description: string;
  date: string;
  attached_to: number | null;
  sizes: Record<string, { url: string; width: number; height: number }>;
}

export interface WpPluginContent {
  id: number;
  type: string;
  url: string;
  slug: string;
  title: string;
  status: string;
  modified: string;
  featured_media: number | null;
  featured_url: string | null;
  featured_alt: string | null;
}

export type WpImagesPage = {
  total: number;
  total_pages: number;
  page: number;
  per_page: number;
  images: WpPluginImage[];
};

const BRIDGE_BOT = "ekoseo_bot";

function wpJsonBase(site: WpPluginSite): string {
  let base = site.wpApiUrl?.trim() ? site.wpApiUrl.trim().replace(/\/+$/, "") : `https://${site.domain}/wp-json`;
  if (!/\/wp-json/.test(base)) base = `${base}/wp-json`;
  return base;
}

function bridgeAuth(site: WpPluginSite): string {
  const token = site.wpAuthToken || "";
  // A pasted "user:password" wins; a bare key belongs to the ekoseo_bot account.
  const pair = token.includes(":") ? token : `${BRIDGE_BOT}:${token}`;
  return `Basic ${Buffer.from(pair).toString("base64")}`;
}

async function bridgeFetch(site: WpPluginSite, path: string, init?: RequestInit): Promise<Response> {
  const res = await fetch(`${wpJsonBase(site)}/ekoseo/v1${path}`, {
    ...init,
    headers: {
      Authorization: bridgeAuth(site),
      "Content-Type": "application/json",
      ...(init?.headers || {}),
    },
    cache: "no-store",
  });
  if (!res.ok) {
    const body = await res.text().catch(() => "");
    throw new Error(`Bridge HTTP ${res.status}: ${body.slice(0, 200)}`);
  }
  return res;
}

async function woiFetch<T>(site: WpPluginSite, path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${wpJsonBase(site)}/waronimage/v1${path}`, {
    ...init,
    headers: {
      "X-Waronimage-Key": site.wpAuthToken || "",
      "Content-Type": "application/json",
      ...(init?.headers || {}),
    },
    cache: "no-store",
  });
  if (!res.ok) {
    const body = await res.text().catch(() => "");
    throw new Error(`Plugin HTTP ${res.status}: ${body.slice(0, 200)}`);
  }
  return (await res.json()) as T;
}

type Mode = "bridge" | "woi";
const modeCache = new Map<string, Mode>();

function cacheKey(site: WpPluginSite): string {
  return `${site.domain}|${site.wpApiUrl || ""}|${site.wpAuthToken || ""}`;
}

async function resolveMode(site: WpPluginSite): Promise<Mode | null> {
  if (!site.wpAuthToken) return null;
  const key = cacheKey(site);
  const cached = modeCache.get(key);
  if (cached) return cached;
  try {
    await bridgeFetch(site, "/site");
    modeCache.set(key, "bridge");
    return "bridge";
  } catch {
    /* not the bridge — try the standalone namespace */
  }
  try {
    await woiFetch(site, "/info");
    modeCache.set(key, "woi");
    return "woi";
  } catch {
    return null;
  }
}

interface BridgeSiteInfo {
  bridge_version: string;
  wordpress: string;
  site_url: string;
  post_types: { slug: string; count: number }[];
}

/** Health check — null when no plugin answers with this key. */
export async function pingPlugin(site: WpPluginSite): Promise<WpPluginInfo | null> {
  const mode = await resolveMode(site);
  if (!mode) return null;

  if (mode === "bridge") {
    try {
      const res = await bridgeFetch(site, "/site");
      const info = (await res.json()) as BridgeSiteInfo;
      let imageCount = 0;
      try {
        const media = await bridgeFetch(site, "/media?page=1&per_page=1");
        imageCount = Number(media.headers.get("x-wp-total")) || 0;
        await media.json().catch(() => null);
      } catch {
        /* /media absent: bridge < 1.5.0 — the caller shows the update path */
      }
      return {
        plugin: "ekoseo-bridge",
        plugin_version: info.bridge_version,
        wp_version: info.wordpress,
        site_name: new URL(info.site_url).hostname,
        site_url: info.site_url,
        image_count: imageCount,
        post_types: (info.post_types || []).map((t) => t.slug),
      };
    } catch (err) {
      console.error("[wpPlugin] bridge ping failed:", (err as Error).message);
      return null;
    }
  }

  try {
    return await woiFetch<WpPluginInfo>(site, "/info");
  } catch (err) {
    console.error("[wpPlugin] ping failed:", (err as Error).message);
    return null;
  }
}

export async function listWpImages(site: WpPluginSite, page = 1, perPage = 50): Promise<WpImagesPage> {
  const mode = await resolveMode(site);
  if (mode === "bridge") {
    const res = await bridgeFetch(site, `/media?page=${page}&per_page=${perPage}`);
    const images = (await res.json()) as WpPluginImage[];
    return {
      images,
      total: Number(res.headers.get("x-wp-total")) || images.length,
      total_pages: Number(res.headers.get("x-wp-totalpages")) || 1,
      page,
      per_page: perPage,
    };
  }
  return woiFetch<WpImagesPage>(site, `/images?page=${page}&per_page=${perPage}`);
}

export async function updateWpImage(
  site: WpPluginSite,
  id: number,
  meta: { alt?: string; title?: string; caption?: string; description?: string }
): Promise<WpPluginImage> {
  const mode = await resolveMode(site);
  if (mode === "bridge") {
    const res = await bridgeFetch(site, `/media/${id}`, { method: "POST", body: JSON.stringify(meta) });
    return (await res.json()) as WpPluginImage;
  }
  return woiFetch<WpPluginImage>(site, `/images/${id}`, { method: "POST", body: JSON.stringify(meta) });
}

export async function uploadWpImage(
  site: WpPluginSite,
  payload: {
    filename: string;
    base64: string;
    alt?: string;
    title?: string;
    caption?: string;
    description?: string;
    attach_to?: number;
    set_featured?: boolean;
  }
): Promise<WpPluginImage> {
  const mode = await resolveMode(site);
  if (mode === "bridge") {
    const res = await bridgeFetch(site, "/media", { method: "POST", body: JSON.stringify(payload) });
    return (await res.json()) as WpPluginImage;
  }
  return woiFetch<WpPluginImage>(site, "/images", { method: "POST", body: JSON.stringify(payload) });
}

/** All published content (pages, posts, CPTs). */
export async function listWpContent(site: WpPluginSite): Promise<WpPluginContent[]> {
  const mode = await resolveMode(site);

  if (mode === "bridge") {
    const siteRes = await bridgeFetch(site, "/site");
    const info = (await siteRes.json()) as BridgeSiteInfo;
    const items: WpPluginContent[] = [];
    for (const pt of info.post_types || []) {
      let page = 1;
      for (;;) {
        const res = await bridgeFetch(site, `/tree?cpt=${encodeURIComponent(pt.slug)}&page=${page}&per_page=200&light=true`);
        const rows = (await res.json()) as {
          id: number; slug: string; title: string; status: string; permalink: string; modified: string;
        }[];
        for (const r of rows) {
          if (r.status !== "publish") continue;
          items.push({
            id: r.id,
            type: pt.slug,
            url: r.permalink,
            slug: r.slug,
            title: r.title,
            status: r.status,
            modified: r.modified,
            featured_media: null,
            featured_url: null,
            featured_alt: null,
          });
        }
        const totalPages = Number(res.headers.get("x-wp-totalpages")) || 1;
        if (page >= totalPages || page >= 20) break;
        page++;
      }
    }
    return items;
  }

  const items: WpPluginContent[] = [];
  let page = 1;
  for (;;) {
    const res = await woiFetch<{ items: WpPluginContent[]; total_pages: number }>(
      site,
      `/content?page=${page}&per_page=200`
    );
    items.push(...res.items);
    if (page >= res.total_pages || page >= 20) break;
    page++;
  }
  return items;
}

export async function setWpFeatured(site: WpPluginSite, postId: number, mediaId: number): Promise<void> {
  const mode = await resolveMode(site);
  if (mode === "bridge") {
    await bridgeFetch(site, `/media/${mediaId}/featured`, { method: "POST", body: JSON.stringify({ post_id: postId }) });
    return;
  }
  await woiFetch(site, `/content/${postId}/featured`, { method: "POST", body: JSON.stringify({ media_id: mediaId }) });
}
