import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { DEFAULT_STYLES } from "@/lib/styleSeeds";

export const dynamic = "force-dynamic";

export async function GET() {
  let styles = await prisma.imageStyle.findMany({ orderBy: { createdAt: "asc" } });
  if (styles.length === 0) {
    await prisma.imageStyle.createMany({ data: DEFAULT_STYLES });
    styles = await prisma.imageStyle.findMany({ orderBy: { createdAt: "asc" } });
  }
  return NextResponse.json({ styles });
}

export async function POST(req: NextRequest) {
  const body = await req.json();
  const { name, description, promptTemplate, niches, formats, exampleUrls } = body;
  if (!name || !promptTemplate) {
    return NextResponse.json({ error: "name and promptTemplate are required" }, { status: 400 });
  }
  const style = await prisma.imageStyle.create({
    data: {
      name,
      description: description ?? "",
      promptTemplate,
      niches: niches ?? [],
      formats: formats ?? { ratio: "16:9", width: 1280, height: 720 },
      exampleUrls: exampleUrls ?? [],
    },
  });
  return NextResponse.json({ style }, { status: 201 });
}
