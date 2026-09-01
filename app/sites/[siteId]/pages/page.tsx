import { PageBrowser } from "@/components/PageBrowser";
import { ReingestButton } from "@/components/ReingestButton";
import { getDict } from "@/lib/i18n-server";

export const dynamic = "force-dynamic";

export default function SitePagesPage({ params }: { params: { siteId: string } }) {
  const dict = getDict();
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-zinc-400">{dict.pagesView.intro}</p>
        <ReingestButton siteId={params.siteId} />
      </div>
      <PageBrowser siteId={params.siteId} />
    </div>
  );
}
