import { prisma, safeQuery } from "@/lib/prisma";
import { WpLibrary } from "@/components/WpLibrary";

export const dynamic = "force-dynamic";

export default async function WpLibraryPage({ params }: { params: { siteId: string } }) {
  const site = await safeQuery(() => prisma.site.findUnique({ where: { id: params.siteId } }), null);
  return <WpLibrary siteId={params.siteId} initialWpApiUrl={site?.wpApiUrl ?? ""} />;
}
