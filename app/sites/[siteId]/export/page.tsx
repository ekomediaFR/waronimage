import { prisma, safeQuery } from "@/lib/prisma";
import { ExportPanel } from "@/components/ExportPanel";

export const dynamic = "force-dynamic";

export default async function ExportPage({ params }: { params: { siteId: string } }) {
  const site = await safeQuery(() => prisma.site.findUnique({ where: { id: params.siteId } }), null);
  const hasWpConfig = Boolean(site?.wpApiUrl && site?.wpAuthToken);
  return <ExportPanel siteId={params.siteId} hasWpConfig={hasWpConfig} />;
}
