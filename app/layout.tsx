import type { Metadata } from "next";
import { Roboto } from "next/font/google";
import { LanguageProvider } from "@/components/LanguageProvider";
import { AppHeader } from "@/components/AppHeader";
import { getLocale } from "@/lib/i18n-server";
import "./globals.css";

const roboto = Roboto({ subsets: ["latin"], weight: ["400", "500", "700"] });

export const metadata: Metadata = {
  title: "War on Image Manager",
  description:
    "SEO image command center — AI-generated and stock images matched, scored and exported to WordPress sites.",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  const locale = getLocale();
  return (
    <html lang={locale}>
      <body className={roboto.className}>
        <LanguageProvider initialLocale={locale}>
          <AppHeader />
          <main className="mx-auto max-w-screen-2xl px-4 py-6">{children}</main>
        </LanguageProvider>
      </body>
    </html>
  );
}
