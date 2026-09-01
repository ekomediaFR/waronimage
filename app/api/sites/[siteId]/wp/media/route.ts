import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { pingPlugin, listWpImages, updateWpImage } from "@/lib/wpPlugin";

export const dynamic = "force-dynamic";
export const maxDuration = 120;

interface Params {
  params: { siteId: string };
}

/** GET — WordPress media library via the War on Image Connect plugin. */
export async function GET(req: NextRequest, { params }: Params) {
  const site = await prisma.site.findUnique({ where: { id: params.siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });
  if (!site.wpAuthToken) return NextResponse.json({ connected: false, reason: "no-key" });

  const info = await pingPlugin(site);
  if (!info) return NextResponse.json({ connected: false, reason: "unreachable" });

  const page = Math.max(1, Number(req.nextUrl.searchParams.get("page")) || 1);
  try {
    const list = await listWpImages(site, page, 24);
    return NextResponse.json({ connected: true, info, ...list });
  } catch (err) {
    const message = (err as Error).message;
    // A bridge older than 1.5.0 answers /site but has no /media routes yet.
    if (info.plugin === "ekoseo-bridge" && /HTTP 404/.test(message)) {
      return NextResponse.json({ connected: false, reason: "outdated", info });
    }
    return NextResponse.json({ connected: false, reason: "error", error: message });
  }
}

/** POST — update one image's SEO metadata in WordPress through the plugin. */
export async function POST(req: NextRequest, { params }: Params) {
  const site = await prisma.site.findUnique({ where: { id: params.siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });

  const body = await req.json();
  const imageId = Number(body.imageId);
  if (!imageId) return NextResponse.json({ error: "imageId required" }, { status: 400 });

  try {
    const image = await updateWpImage(site, imageId, {
      alt: body.alt,
      title: body.title,
      caption: body.caption,
      description: body.description,
    });
    return NextResponse.json({ image });
  } catch (err) {
    return NextResponse.json({ error: (err as Error).message }, { status: 502 });
  }
}
