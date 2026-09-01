import { SitemapIngester } from "@/components/SitemapIngester";

export default function NewSitePage() {
  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Add a client site</h1>
        <p className="mt-1 text-sm text-zinc-400">
          War on Image reads the sitemap, understands each page&apos;s intent with GPT-4o, and prepares it for
          AI-image generation and stock-photo matching.
        </p>
      </div>
      <SitemapIngester />
    </div>
  );
}
