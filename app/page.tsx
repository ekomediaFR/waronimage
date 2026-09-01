import Link from "next/link";
import { prisma, safeQuery } from "@/lib/prisma";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";

export const dynamic = "force-dynamic";

export default async function HomePage() {
  const sites = await safeQuery(
    () =>
      prisma.site.findMany({
        orderBy: { createdAt: "desc" },
        include: { _count: { select: { pages: true, stockImages: true } } },
      }),
    null
  );

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Client sites</h1>
        <p className="mt-1 text-sm text-zinc-400">
          Pick a site to manage its AI images, stock photos and WordPress exports.
        </p>
      </div>

      {sites === null ? (
        <Card className="border-amber-800 bg-amber-950/20">
          <CardContent className="p-6 text-sm text-amber-300">
            <p className="font-semibold">Database not connected.</p>
            <p className="mt-1 text-amber-400/80">
              Set <code>DATABASE_URL</code> in your environment (Supabase Postgres) and run{" "}
              <code>npx prisma db push</code> to bring War on Image online.
            </p>
          </CardContent>
        </Card>
      ) : sites.length === 0 ? (
        <Card>
          <CardContent className="p-10 text-center">
            <p className="text-zinc-400">No sites yet.</p>
            <Link href="/sites/new" className="mt-2 inline-block text-amber-400 hover:underline">
              Add your first site →
            </Link>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {sites.map((site) => (
            <Link key={site.id} href={`/sites/${site.id}/pages`}>
              <Card className="transition-colors hover:border-amber-600/60">
                <CardHeader>
                  <CardTitle className="text-zinc-100 normal-case tracking-normal text-base">{site.name}</CardTitle>
                  <p className="text-xs text-zinc-500">{site.domain}</p>
                </CardHeader>
                <CardContent className="flex gap-2">
                  <Badge>{site._count.pages} pages</Badge>
                  <Badge>{site._count.stockImages} stock photos</Badge>
                  {site.wpApiUrl && <Badge variant="blue">WP connected</Badge>}
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
