"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";
import { useI18n } from "./LanguageProvider";

export function SiteTabs({ siteId }: { siteId: string }) {
  const pathname = usePathname();
  const { dict } = useI18n();

  const tabs = [
    { slug: "pages", label: dict.tabs.pages },
    { slug: "ai-images", label: dict.tabs.ai },
    { slug: "stock", label: dict.tabs.stock },
    { slug: "export", label: dict.tabs.export },
  ];

  return (
    <nav className="mb-5 flex gap-1 border-b border-zinc-800">
      {tabs.map((tab) => {
        const href = `/sites/${siteId}/${tab.slug}`;
        const active = pathname?.startsWith(href);
        return (
          <Link
            key={tab.slug}
            href={href}
            className={cn(
              "-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors",
              active
                ? "border-amber-500 text-amber-400"
                : "border-transparent text-zinc-400 hover:text-zinc-200"
            )}
          >
            {tab.label}
          </Link>
        );
      })}
    </nav>
  );
}
