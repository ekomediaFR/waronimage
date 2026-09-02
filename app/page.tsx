import Link from "next/link";
import { prisma, safeQuery } from "@/lib/prisma";
import { getDict } from "@/lib/i18n-server";
import { fmt } from "@/lib/i18n";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";

export const dynamic = "force-dynamic";

export default async function HomePage() {
  const dict = getDict();
  const sites = await safeQuery(
    () =>
      prisma.site.findMany({
        orderBy: { createdAt: "desc" },
        include: {
          _count: {
            select: { pages: true, stockImages: true, wpPages: true, wpMedia: true, assignments: true },
          },
        },
      }),
    null
  );

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">{dict.home.title}</h1>
        <p className="mt-1 text-sm text-zinc-400">{dict.home.subtitle}</p>
      </div>

      {sites === null ? (
        <Card className="border-amber-800 bg-amber-950/20">
          <CardContent className="p-6 text-sm text-amber-300">
            <p className="font-semibold">{dict.home.dbDown}</p>
            <p className="mt-1 text-amber-400/80">{dict.home.dbHint}</p>
          </CardContent>
        </Card>
      ) : sites.length === 0 ? (
        <Card>
          <CardContent className="p-10 text-center">
            <p className="text-zinc-400">{dict.home.noSites}</p>
            <Link href="/sites/new" className="mt-2 inline-block text-amber-400 hover:underline">
              {dict.home.addFirst}
            </Link>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {sites.map((site) => (
            <Link key={site.id} href={`/sites/${site.id}/sync`}>
              <Card className="transition-colors hover:border-amber-600/60">
                <CardHeader>
                  <CardTitle className="text-zinc-100 normal-case tracking-normal text-base">{site.name}</CardTitle>
                  <p className="text-xs text-zinc-500">{site.domain}</p>
                </CardHeader>
                <CardContent className="flex flex-wrap gap-2">
                  <Badge variant="blue">{fmt(dict.home.wpPages, { n: site._count.wpPages })}</Badge>
                  <Badge variant="blue">{fmt(dict.home.wpMedia, { n: site._count.wpMedia })}</Badge>
                  {site._count.pages > 0 && <Badge>{fmt(dict.home.pagesCount, { n: site._count.pages })}</Badge>}
                  {site._count.stockImages > 0 && (
                    <Badge>{fmt(dict.home.stockCount, { n: site._count.stockImages })}</Badge>
                  )}
                  {(site.wpApiUrl || site.wpAppPassword || site.wpJwtToken || site.wpAuthToken) && (
                    <Badge variant="green">{dict.common.wpConnected}</Badge>
                  )}
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
