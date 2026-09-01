import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

interface Params {
  params: { id: string };
}

/** PATCH — approve or edit a stock assignment. */
export async function PATCH(req: NextRequest, { params }: Params) {
  const body = await req.json();
  const data: Record<string, unknown> = {};
  if (typeof body.approved === "boolean") data.approved = body.approved;
  if (typeof body.altText === "string") data.altText = body.altText;
  if (typeof body.filename === "string") data.filename = body.filename;
  if (typeof body.position === "string") data.position = body.position;
  const assignment = await prisma.stockAssignment.update({ where: { id: params.id }, data });
  return NextResponse.json({ assignment });
}

export async function DELETE(_req: NextRequest, { params }: Params) {
  await prisma.stockAssignment.delete({ where: { id: params.id } });
  return NextResponse.json({ ok: true });
}
