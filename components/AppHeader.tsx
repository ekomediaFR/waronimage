"use client";

import Link from "next/link";
import { useI18n } from "./LanguageProvider";
import type { Locale } from "@/lib/i18n";

/** Top menu: brand, nav links, and the FR/EN language select box. */
export function AppHeader() {
  const { locale, dict, setLocale } = useI18n();

  return (
    <header className="sticky top-0 z-40 border-b border-zinc-800 bg-zinc-950/90 backdrop-blur">
      <div className="mx-auto flex h-14 max-w-screen-2xl items-center gap-6 px-4">
        <Link href="/" className="flex items-center gap-2">
          <span className="flex h-7 w-7 items-center justify-center rounded bg-amber-500 font-black text-zinc-950">
            W
          </span>
          <span className="text-sm font-bold uppercase tracking-widest text-zinc-100">
            War on Image
          </span>
        </Link>
        <nav className="flex items-center gap-4 text-sm text-zinc-400">
          <Link href="/" className="hover:text-zinc-100">{dict.nav.sites}</Link>
          <Link href="/dashboard" className="hover:text-zinc-100">{dict.nav.dashboard}</Link>
          <Link href="/sites/new" className="hover:text-zinc-100">{dict.nav.addSite}</Link>
        </nav>
        <div className="ml-auto flex items-center gap-2">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#71717a" strokeWidth="1.8" aria-hidden>
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3c2.5 2.6 3.8 5.7 3.8 9S14.5 18.4 12 21c-2.5-2.6-3.8-5.7-3.8-9S9.5 5.6 12 3z" />
          </svg>
          <select
            aria-label="Language"
            value={locale}
            onChange={(e) => setLocale(e.target.value as Locale)}
            className="h-8 rounded-md border border-zinc-700 bg-zinc-900 px-2 text-sm text-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
          >
            <option value="fr">Français</option>
            <option value="en">English</option>
          </select>
        </div>
      </div>
    </header>
  );
}
