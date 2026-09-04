// Bulk export of approved assignments back to WordPress:
// 1) write the SEO metadata onto the media item, 2) set featured_media on the page.
// Idempotent — re-exporting just updates WP again, no duplicates possible.

import type { Assignment, Site, WpMedia, WpPage } from "@prisma/client";
import { prisma } from "./prisma";
import { buildAuthHeaders, getRestBase, hasWpCredentials, isJupiterKey, wpFetch, wpV2Base } from "./wpRest";
import { setWpFeatured, updateWpImage } from "./wpPlugin";
import { finishSyncLog, startSyncLog } from "./wpSync";

export type FullAssignment = Assignment & { page: WpPage; media: WpMedia };

export interface BulkExportResult {
  success: number;
  failed: number;
  errors: string[];
}

/** Push one assignment to WordPress. Throws with a readable message on failure. */
export async function exportAssignment(site: Site, assignment: FullAssignment): Promise<void> {
  if (!hasWpCredentials(site)) {
    throw new Error("No WordPress credentials on this site — add them in the site settings.");
  }

  // JUPITER keys (ekoseo_bot) have no core wp/v2 edit caps — write via the bridge.
  if (isJupiterKey(site)) {
    await exportViaBridge(site, assignment);
  } else {
    try {
      await exportViaCore(site, assignment);
    } catch (err) {
      // Core said "no rights" but a bridge token exists — try the bridge channel.
      const msg = (err as Error).message;
      if (site.wpAuthToken?.trim() && /WP (401|403)/.test(msg)) {
        await exportViaBridge(site, assignment);
      } else {
        throw err;
      }
    }
  }

  await prisma.assignment.update({
    where: { id: assignment.id },
    data: { exported: true, exportedAt: new Date(), exportError: null },
  });
}

function seoMetaBody(assignment: FullAssignment): Record<string, string> {
  const metaBody: Record<string, string> = {};
  if (assignment.seoAltText) metaBody.alt_text = assignment.seoAltText;
  if (assignment.seoTitle) metaBody.title = assignment.seoTitle;
  if (assignment.seoCaption) metaBody.caption = assignment.seoCaption;
  if (assignment.seoDescription) metaBody.description = assignment.seoDescription;
  return metaBody;
}

/** Standard channel: core wp/v2 endpoints with the site credentials. */
async function exportViaCore(site: Site, assignment: FullAssignment): Promise<void> {
  const headers = buildAuthHeaders(site);
  const v2 = wpV2Base(site);

  // 1. SEO metadata on the media item (plain strings — WP casts them to raw content).
  const metaBody = seoMetaBody(assignment);
  if (Object.keys(metaBody).length > 0) {
    const metaRes = await wpFetch(`${v2}/media/${assignment.media.wpId}`, {
      method: "POST",
      headers,
      body: JSON.stringify(metaBody),
    });
    if (!metaRes.ok) {
      const err = await metaRes.text().catch(() => "");
      throw new Error(`media metadata update failed — WP ${metaRes.status}: ${err.slice(0, 200)}`);
    }
  }

  // 2. featured_media on the page/post/CPT.
  const restBase = getRestBase(assignment.page.wpType, assignment.page.restBase);
  const featRes = await wpFetch(`${v2}/${restBase}/${assignment.page.wpId}`, {
    method: "POST",
    headers,
    body: JSON.stringify({ featured_media: assignment.media.wpId }),
  });
  if (!featRes.ok) {
    const err = await featRes.text().catch(() => "");
    throw new Error(`featured image update failed — WP ${featRes.status}: ${err.slice(0, 200)}`);
  }
}

/** EkoSEO Bridge channel: /ekoseo/v1 media + featured endpoints (ekoseo_bot rights). */
async function exportViaBridge(site: Site, assignment: FullAssignment): Promise<void> {
  const pluginSite = { domain: site.domain, wpApiUrl: site.wpApiUrl, wpAuthToken: site.wpAuthToken };

  const metaBody = seoMetaBody(assignment);
  if (Object.keys(metaBody).length > 0) {
    try {
      await updateWpImage(pluginSite, assignment.media.wpId, {
        alt: metaBody.alt_text,
        title: metaBody.title,
        caption: metaBody.caption,
        description: metaBody.description,
      });
    } catch (err) {
      throw new Error(`media metadata update failed (bridge) — ${(err as Error).message.slice(0, 200)}`);
    }
  }

  try {
    await setWpFeatured(pluginSite, assignment.page.wpId, assignment.media.wpId);
  } catch (err) {
    throw new Error(`featured image update failed (bridge) — ${(err as Error).message.slice(0, 200)}`);
  }
}

/**
 * Export every approved, not-yet-exported assignment of a site.
 * One failure never stops the rest — errors land on Assignment.exportError.
 * Progress is visible live: each assignment is marked in the DB as it lands,
 * so the export/status endpoint can be polled while this runs.
 */
export async function bulkExport(siteId: string, limit?: number): Promise<BulkExportResult> {
  const site = await prisma.site.findUnique({ where: { id: siteId } });
  if (!site) throw new Error("Site not found");

  const assignments = await prisma.assignment.findMany({
    where: { siteId, approved: true, exported: false },
    include: { page: true, media: true },
    orderBy: { matchScore: "desc" },
    ...(limit ? { take: limit } : {}),
  });

  const logId = await startSyncLog(siteId, "bulk-export");
  const result: BulkExportResult = { success: 0, failed: 0, errors: [] };

  for (const assignment of assignments) {
    try {
      await exportAssignment(site, assignment);
      result.success++;
    } catch (e) {
      result.failed++;
      const errMsg = (e as Error).message || "Unknown error";
      result.errors.push(`"${assignment.page.title}": ${errMsg}`);
      await prisma.assignment
        .update({ where: { id: assignment.id }, data: { exportError: errMsg } })
        .catch(() => undefined);
    }
  }

  await finishSyncLog(logId, {
    status: result.failed > 0 && result.success === 0 ? "error" : "done",
    exportCount: result.success,
    errorMsg: result.errors.length ? result.errors.slice(0, 5).join(" | ").slice(0, 900) : undefined,
  });

  return result;
}
