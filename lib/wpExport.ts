interface WpSiteConfig {
  wpApiUrl: string; // e.g. https://example.com/wp-json
  wpAuthToken: string; // JWT ("Bearer") or app-password base64 ("Basic")
}

export interface WpExportResult {
  mediaId: number;
  mediaUrl: string;
}

function authHeader(token: string): string {
  // Application passwords are usually provided as "user:pass" — send Basic then.
  if (token.includes(":")) return `Basic ${Buffer.from(token).toString("base64")}`;
  return `Bearer ${token}`;
}

/** Upload an image to the WordPress media library and set its SEO metadata. */
export async function exportImageToWordPress(
  site: WpSiteConfig,
  image: { url: string; filename: string; altText: string; title: string; caption?: string | null; description?: string | null },
  featuredForPostId?: number | null,
  postType: string = "pages"
): Promise<WpExportResult> {
  const imgRes = await fetch(image.url);
  if (!imgRes.ok) throw new Error(`Could not fetch image binary: HTTP ${imgRes.status}`);
  const buffer = Buffer.from(await imgRes.arrayBuffer());

  const base = site.wpApiUrl.replace(/\/$/, "");
  const auth = authHeader(site.wpAuthToken);

  const uploadRes = await fetch(`${base}/wp/v2/media`, {
    method: "POST",
    headers: {
      Authorization: auth,
      "Content-Disposition": `attachment; filename="${image.filename}"`,
      "Content-Type": "image/webp",
    },
    body: new Uint8Array(buffer),
  });
  if (!uploadRes.ok) {
    throw new Error(`WP media upload failed: HTTP ${uploadRes.status} ${await uploadRes.text().then((t) => t.slice(0, 200))}`);
  }
  const media = (await uploadRes.json()) as { id: number; source_url: string };

  const metaRes = await fetch(`${base}/wp/v2/media/${media.id}`, {
    method: "POST",
    headers: { Authorization: auth, "Content-Type": "application/json" },
    body: JSON.stringify({
      alt_text: image.altText,
      title: image.title,
      caption: image.caption || "",
      description: image.description || "",
    }),
  });
  if (!metaRes.ok) console.error(`WP media meta update failed: HTTP ${metaRes.status}`);

  if (featuredForPostId) {
    const featRes = await fetch(`${base}/wp/v2/${postType}/${featuredForPostId}`, {
      method: "POST",
      headers: { Authorization: auth, "Content-Type": "application/json" },
      body: JSON.stringify({ featured_media: media.id }),
    });
    if (!featRes.ok) console.error(`WP featured image assignment failed: HTTP ${featRes.status}`);
  }

  return { mediaId: media.id, mediaUrl: media.source_url };
}
