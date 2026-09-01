"use client";

import { createContext, useContext, useState } from "react";
import { useRouter } from "next/navigation";
import { DICTS, LOCALE_COOKIE, type Dict, type Locale } from "@/lib/i18n";

interface I18nContextValue {
  locale: Locale;
  dict: Dict;
  setLocale: (locale: Locale) => void;
}

const I18nContext = createContext<I18nContextValue>({
  locale: "fr",
  dict: DICTS.fr,
  setLocale: () => {},
});

export function LanguageProvider({
  initialLocale,
  children,
}: {
  initialLocale: Locale;
  children: React.ReactNode;
}) {
  const [locale, setLocaleState] = useState<Locale>(initialLocale);
  const router = useRouter();

  function setLocale(next: Locale) {
    document.cookie = `${LOCALE_COOKIE}=${next};path=/;max-age=31536000;samesite=lax`;
    setLocaleState(next);
    router.refresh(); // re-render server components with the new cookie
  }

  return (
    <I18nContext.Provider value={{ locale, dict: DICTS[locale], setLocale }}>
      {children}
    </I18nContext.Provider>
  );
}

export function useI18n() {
  return useContext(I18nContext);
}
