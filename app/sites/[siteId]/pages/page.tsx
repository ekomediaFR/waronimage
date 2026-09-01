import { PageBrowser } from "@/components/PageBrowser";
import { ReingestButton } from "@/components/ReingestButton";

export default function SitePagesPage({ params }: { params: { siteId: string } }) {
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-zinc-400">
          All sitemap pages with their intent, niche and image coverage. Click a row for details.
        </p>
        <ReingestButton siteId={params.siteId} />
      </div>
      <PageBrowser siteId={params.siteId} />
    </div>
  );
}
