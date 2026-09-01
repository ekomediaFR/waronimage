import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { pingPlugin, listWpContent } from "@/lib/wpPlugin";

export const dynamic = "force-dynamic";
export const maxDuration = 120;

function pathKey(url: string): string {
  try {
    return new URL(url).pathname.replace(/\/+$/, "") || "/";
  } catch {
    return url;
  }
}

/**
 * POST — pull all WordPress content via the plugin and link Page rows
 * to their real WordPress IDs (wpPostId), matched by URL path.
 */
export async function POST(_req: Request, { params }: { params: { siteId: string } }) {
  const site = await prisma.site.findUnique({ where: { id: params.siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });

  const info = await pingPlugin(site);
  if (!info) return NextResponse.json({ error: "Plugin unreachable — check the API key." }, { status: 502 });

  const content = await listWpContent(site);
  const byPath = new Map(content.map((c) => [pathKey(c.url), c]));

  const pages = await prisma.page.findMany({ where: { siteId: site.id } });
  let matched = 0;
  for (const page of pages) {
    const wp = byPath.get(pathKey(page.url));
    if (wp && page.wpPostId !== wp.id) {
      await prisma.page.update({ where: { id: page.id }, data: { wpPostId: wp.id } });
      matched++;
    } else if (wp) {
      matched++;
    }
  }

  return NextResponse.json({ totalWp: content.length, pages: pages.length, matched });
}
