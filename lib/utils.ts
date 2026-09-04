import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

export function pct(part: number, total: number): string {
  if (!total) return "0%";
  return `${Math.round((part / total) * 100)}%`;
}

/**
 * Make a string safe to persist through Prisma/Postgres:
 * strips NUL + other control chars and drops lone/unpaired UTF-16 surrogates
 * (which `slice`/`substring` create when they cut through an emoji or other
 * astral character). Unpaired surrogates make the Prisma query engine throw
 * "unexpected end of hex escape".
 */
export function sanitizeText(s: string): string {
  return s
    // eslint-disable-next-line no-control-regex
    .replace(/[\u0000-\u001F\u007F-\u009F]/g, "")
    .replace(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])/g, "") // high surrogate w/o low
    .replace(/(^|[^\uD800-\uDBFF])[\uDC00-\uDFFF]/g, "$1"); // low surrogate w/o high
}

/** Truncate to `max` characters without splitting a surrogate pair, then sanitize. */
export function truncateSafe(s: string, max: number): string {
  let out = s;
  if (s.length > max) {
    let end = max - 1;
    // Don't cut between a high and low surrogate.
    const code = s.charCodeAt(end - 1);
    if (code >= 0xd800 && code <= 0xdbff) end -= 1;
    out = s.slice(0, end).trimEnd() + "…";
  }
  return sanitizeText(out);
}

/**
 * fetch + JSON with readable failures. A crashed API route answers with an
 * empty body — plain res.json() then throws "Unexpected end of JSON input";
 * this surfaces the HTTP status and the server's {error} message instead.
 */
export async function fetchJson<T = unknown>(url: string, init?: RequestInit): Promise<T> {
  const res = await fetch(url, init);
  const text = await res.text();
  let data: unknown = null;
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      /* non-JSON body — fall through to the status check */
    }
  }
  if (!res.ok) {
    const msg =
      (data as { error?: string; message?: string } | null)?.error ||
      (data as { message?: string } | null)?.message ||
      `HTTP ${res.status}${text ? "" : " — empty server response (database offline?)"}`;
    throw new Error(msg);
  }
  if (data === null) throw new Error("Empty response from server");
  return data as T;
}
