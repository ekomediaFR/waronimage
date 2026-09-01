import { cookies } from "next/headers";
import { DICTS, LOCALE_COOKIE, type Dict, type Locale } from "./i18n";

/** Server-side locale from the cookie set by the header's language selector. Defaults to French. */
export function getLocale(): Locale {
  try {
    const v = cookies().get(LOCALE_COOKIE)?.value;
    return v === "en" ? "en" : "fr";
  } catch {
    return "fr";
  }
}

export function getDict(): Dict {
  return DICTS[getLocale()];
}
