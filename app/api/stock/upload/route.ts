import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { storeImage } from "@/lib/supabase";
import { kebab } from "@/lib/seoMeta";
import sharp from "sharp";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

/**
 * POST /api/stock/upload  (multipart/form-data)
 * Fields: siteId, files[] — bulk stock photo upload.
 * Stores originals under stock/{siteId}/originals/ and creates StockImage rows.
 */
export async function POST(req: NextRequest) {
  const form = await req.formData();
  const siteId = String(form.get("siteId") || "");
  if (!siteId) return NextResponse.json({ error: "siteId is required" }, { status: 400 });

  const site = await prisma.site.findUnique({ where: { id: siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });

  const files = form.getAll("files").filter((f): f is File => f instanceof File);
  if (!files.length) return NextResponse.json({ error: "No files uploaded" }, { status: 400 });

  const created: { id: string; filename: string; originalUrl: string }[] = [];
  const errors: { filename: string; error: string }[] = [];

  for (const file of files) {
    try {
      const raw = Buffer.from(await file.arrayBuffer());
      // Normalize to WebP right away (keeps storage consistent and vision-friendly)
      const webp = await sharp(raw).rotate().webp({ quality: 85 }).toBuffer();
      const base = kebab(file.name.replace(/\.[a-z0-9]+$/i, "")) || "photo";
      const filename = `${base}-${Date.now().toString(36)}.webp`;
      const url = await storeImage(webp, `stock/${siteId}/originals/${filename}`);
      const record = await prisma.stockImage.create({
        data: { siteId, originalUrl: url, filename, status: "unprocessed" },
      });
      created.push({ id: record.id, filename, originalUrl: url });
    } catch (err) {
      errors.push({ filename: file.name, error: (err as Error).message });
    }
  }

  return NextResponse.json({ uploaded: created.length, images: created, errors });
}
