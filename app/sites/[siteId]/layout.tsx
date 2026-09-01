import { prisma, safeQuery } from "@/lib/prisma";
import { getDict } from "@/lib/i18n-server";
import { SiteTabs } from "@/components/SiteTabs";
import { Badge } from "@/components/ui/badge";
import Link from "next/link";

export const dynamic = "force-dynamic";

export default async function SiteLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: { siteId: string };
}) {
  const dict = getDict();
  const site = await safeQuery(() => prisma.site.findUnique({ where: { id: params.siteId } }), null);

  if (!site) {
    return (
      <div className="py-16 text-center text-zinc-400">
        {dict.siteLayout.notFound}{" "}
        <Link href="/" className="text-amber-400 hover:underline">{dict.siteLayout.back}</Link>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <h1 className="text-xl font-bold tracking-tight">{site.name}</h1>
        <a href={`https://${site.domain}`} target="_blank" rel="noreferrer" className="text-sm text-sky-400 hover:underline">
          {site.domain}
        </a>
        {site.wpApiUrl && <Badge variant="blue">{dict.common.wpConnected}</Badge>}
        <span
          className="ml-auto flex items-center gap-1.5 text-xs text-zinc-500"
          title={dict.siteLayout.brandTitle}
        >
          {Object.values((site.brandColors ?? {}) as Record<string, string>).map((c, i) => (
            <span key={i} className="h-4 w-4 rounded-full border border-zinc-700" style={{ backgroundColor: c }} />
          ))}
          {site.fontStyle}
        </span>
      </div>
      <SiteTabs siteId={site.id} />
      {children}
    </div>
  );
}
