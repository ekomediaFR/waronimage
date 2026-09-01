import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

interface Params {
  params: { styleId: string };
}

export async function PUT(req: NextRequest, { params }: Params) {
  const body = await req.json();
  const style = await prisma.imageStyle.update({ where: { id: params.styleId }, data: body });
  return NextResponse.json({ style });
}

export async function DELETE(_req: NextRequest, { params }: Params) {
  await prisma.imageStyle.delete({ where: { id: params.styleId } });
  return NextResponse.json({ ok: true });
}
