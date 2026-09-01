// Client for the "War on Image Connect" WordPress plugin (namespace waronimage/v1).

export interface WpPluginSite {
  domain: string;
  wpApiUrl?: string | null;
  wpAuthToken?: string | null; // the plugin API key
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

interface Paged {
  total: number;
  total_pages: number;
  page: number;
  per_page: number;
}

export type WpImagesPage = Paged & { images: WpPluginImage[] };
export type WpContentPage = Paged & { items: WpPluginContent[] };

/** waronimage/v1 endpoint base for a site (wpApiUrl wins, domain fallback). */
export function pluginEndpoint(site: WpPluginSite): string {
  let base = site.wpApiUrl?.trim() ? site.wpApiUrl.trim().replace(/\/+$/, "") : `https://${site.domain}/wp-json`;
  if (!/\/wp-json/.test(base)) base = `${base}/wp-json`;
  return `${base}/waronimage/v1`;
}

async function pluginFetch<T>(site: WpPluginSite, path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${pluginEndpoint(site)}${path}`, {
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

/** Health check — null when the plugin is unreachable or the key is wrong. */
export async function pingPlugin(site: WpPluginSite): Promise<WpPluginInfo | null> {
  if (!site.wpAuthToken) return null;
  try {
    return await pluginFetch<WpPluginInfo>(site, "/info");
  } catch (err) {
    console.error("[wpPlugin] ping failed:", (err as Error).message);
    return null;
  }
}

export function listWpImages(site: WpPluginSite, page = 1, perPage = 50): Promise<WpImagesPage> {
  return pluginFetch<WpImagesPage>(site, `/images?page=${page}&per_page=${perPage}`);
}

export function updateWpImage(
  site: WpPluginSite,
  id: number,
  meta: { alt?: string; title?: string; caption?: string; description?: string }
): Promise<WpPluginImage> {
  return pluginFetch<WpPluginImage>(site, `/images/${id}`, { method: "POST", body: JSON.stringify(meta) });
}

export function uploadWpImage(
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
  return pluginFetch<WpPluginImage>(site, "/images", { method: "POST", body: JSON.stringify(payload) });
}

/** All published content (pages, posts, CPTs), following pagination. */
export async function listWpContent(site: WpPluginSite): Promise<WpPluginContent[]> {
  const items: WpPluginContent[] = [];
  let page = 1;
  for (;;) {
    const res = await pluginFetch<WpContentPage>(site, `/content?page=${page}&per_page=200`);
    items.push(...res.items);
    if (page >= res.total_pages || page >= 20) break;
    page++;
  }
  return items;
}

export function setWpFeatured(site: WpPluginSite, postId: number, mediaId: number): Promise<WpPluginContent> {
  return pluginFetch<WpPluginContent>(site, `/content/${postId}/featured`, {
    method: "POST",
    body: JSON.stringify({ media_id: mediaId }),
  });
}
