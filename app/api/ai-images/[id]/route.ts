import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

interface Params {
  params: { id: string };
}

/** PATCH — approve/reject or edit SEO metadata of an AI image. */
export async function PATCH(req: NextRequest, { params }: Params) {
  const body = await req.json();
  const allowed = ["status", "altText", "title", "caption", "description", "filename"] as const;
  const data: Record<string, string> = {};
  for (const key of allowed) if (typeof body[key] === "string") data[key] = body[key];
  const image = await prisma.aiImage.update({ where: { id: params.id }, data });
  return NextResponse.json({ image });
}

export async function DELETE(_req: NextRequest, { params }: Params) {
  await prisma.aiImage.delete({ where: { id: params.id } });
  return NextResponse.json({ ok: true });
}
