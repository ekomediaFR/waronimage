import { SitemapIngester } from "@/components/SitemapIngester";
import { getDict } from "@/lib/i18n-server";

export const dynamic = "force-dynamic";

export default function NewSitePage() {
  const dict = getDict();
  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">{dict.newSite.title}</h1>
        <p className="mt-1 text-sm text-zinc-400">{dict.newSite.subtitle}</p>
      </div>
      <SitemapIngester />
    </div>
  );
}
