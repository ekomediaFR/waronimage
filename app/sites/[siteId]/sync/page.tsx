import { prisma, safeQuery } from "@/lib/prisma";
import { hasWpCredentials } from "@/lib/wpRest";
import { WpSyncDashboard } from "@/components/WpSyncDashboard";

export const dynamic = "force-dynamic";

export default async function SyncPage({ params }: { params: { siteId: string } }) {
  const site = await safeQuery(() => prisma.site.findUnique({ where: { id: params.siteId } }), null);
  const hasCreds = site ? hasWpCredentials(site) : false;
  return <WpSyncDashboard siteId={params.siteId} hasCreds={hasCreds} />;
}
