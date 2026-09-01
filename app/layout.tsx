import type { Metadata } from "next";
import { Inter } from "next/font/google";
import Link from "next/link";
import "./globals.css";

const inter = Inter({ subsets: ["latin"] });

export const metadata: Metadata = {
  title: "War on Image Manager",
  description:
    "SEO image command center — AI-generated and stock images matched, scored and exported to WordPress sites.",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en" className="dark">
      <body className={inter.className}>
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
              <Link href="/" className="hover:text-zinc-100">Sites</Link>
              <Link href="/dashboard" className="hover:text-zinc-100">Dashboard</Link>
              <Link href="/sites/new" className="hover:text-zinc-100">+ Add site</Link>
            </nav>
          </div>
        </header>
        <main className="mx-auto max-w-screen-2xl px-4 py-6">{children}</main>
      </body>
    </html>
  );
}
