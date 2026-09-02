import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

interface Params {
  params: { id: string };
}

/**
 * PATCH — manual override on a WP assignment: swap the media, edit the SEO
 * metadata, approve/unapprove. Swapping marks the assignment manual so a
 * re-match never overwrites it, and resets its export status.
 */
export async function PATCH(req: NextRequest, { params }: Params) {
  const body = await req.json();
  const data: Record<string, unknown> = {};

  if (typeof body.approved === "boolean") data.approved = body.approved;
  for (const field of ["seoAltText", "seoTitle", "seoCaption", "seoDescription", "seoFilename"]) {
    if (typeof body[field] === "string") data[field] = body[field];
  }
  if (typeof body.mediaId === "string" && body.mediaId) {
    const existing = await prisma.assignment.findUnique({ where: { id: params.id } });
    if (!existing) return NextResponse.json({ error: "Assignment not found" }, { status: 404 });
    if (body.mediaId !== existing.mediaId) {
      data.mediaId = body.mediaId;
      data.assignedBy = "manual";
      data.matchScore = 1;
      data.matchReason = "manual selection";
      data.exported = false;
      data.exportedAt = null;
      data.exportError = null;
    }
  }

  const assignment = await prisma.assignment.update({
    where: { id: params.id },
    data,
    include: { page: true, media: true },
  });
  return NextResponse.json({ assignment });
}

/** DELETE — remove the assignment entirely (the page goes back to unmatched). */
export async function DELETE(_req: NextRequest, { params }: Params) {
  await prisma.assignment.delete({ where: { id: params.id } });
  return NextResponse.json({ ok: true });
}
