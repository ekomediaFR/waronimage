import { prisma, safeQuery } from "@/lib/prisma";
import { hasWpCredentials } from "@/lib/wpRest";
import { WpExportPanel } from "@/components/WpExportPanel";

export const dynamic = "force-dynamic";

export default async function WpExportPage({ params }: { params: { siteId: string } }) {
  const site = await safeQuery(() => prisma.site.findUnique({ where: { id: params.siteId } }), null);
  const hasCreds = site ? hasWpCredentials(site) : false;
  return <WpExportPanel siteId={params.siteId} hasCreds={hasCreds} />;
}
