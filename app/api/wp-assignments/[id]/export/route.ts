import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { exportAssignment } from "@/lib/wpBulkExport";

export const dynamic = "force-dynamic";
export const maxDuration = 60;

/** POST — export one approved assignment to WordPress (used by the retry button). */
export async function POST(_req: NextRequest, { params }: { params: { id: string } }) {
  const assignment = await prisma.assignment.findUnique({
    where: { id: params.id },
    include: { page: true, media: true },
  });
  if (!assignment) return NextResponse.json({ error: "Assignment not found" }, { status: 404 });
  if (!assignment.approved) {
    return NextResponse.json({ error: "Only approved assignments can be exported." }, { status: 400 });
  }

  const site = await prisma.site.findUnique({ where: { id: assignment.siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });

  try {
    await exportAssignment(site, assignment);
    return NextResponse.json({ ok: true });
  } catch (err) {
    const message = (err as Error).message;
    await prisma.assignment
      .update({ where: { id: assignment.id }, data: { exportError: message } })
      .catch(() => undefined);
    return NextResponse.json({ error: message }, { status: 502 });
  }
}
