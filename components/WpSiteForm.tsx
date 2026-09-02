"use client";

// WP-first add-site form: name + domain + WordPress credentials with a live
// connection test. Sitemap/brand config is an optional fold-out (AI phase).

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input, Label, Select } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { useI18n } from "@/components/LanguageProvider";
import { fmt } from "@/lib/i18n";
import type { WpConnectionTestDto } from "@/lib/types";

export function WpSiteForm() {
  const { dict } = useI18n();
  const router = useRouter();

  const [form, setForm] = useState({
    name: "",
    domain: "",
    language: "fr",
    wpApiUrl: "",
    wpAuthMethod: "app-password",
    wpJwtToken: "",
    wpAppUser: "",
    wpAppPassword: "",
    wpAuthToken: "",
    sitemapUrl: "",
    primary: "#111827",
    secondary: "#f59e0b",
    accent: "#dc2626",
    fontStyle: "sans",
  });
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState<{ kind: "ok" | "warn" | "err"; text: string } | null>(null);
  const [saving, setSaving] = useState(false);
  const [statusMsg, setStatusMsg] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((f) => ({ ...f, [key]: e.target.value }));

  function credentialPayload() {
    return {
      domain: form.domain.trim().replace(/^https?:\/\//, "").replace(/\/.*$/, ""),
      wpApiUrl: form.wpApiUrl.trim() || undefined,
      wpAuthMethod: form.wpAuthMethod,
      wpJwtToken: form.wpJwtToken.trim() || undefined,
      wpAppUser: form.wpAppUser.trim() || undefined,
      wpAppPassword: form.wpAppPassword || undefined,
      wpAuthToken: form.wpAuthToken.trim() || undefined,
    };
  }

  async function testConnection() {
    setTesting(true);
    setTestResult(null);
    try {
      const res = await fetch("/api/sites/test-connection", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(credentialPayload()),
      });
      const json = (await res.json()) as WpConnectionTestDto & { error?: string };
      if (json.ok && json.authenticated) {
        setTestResult({
          kind: "ok",
          text: fmt(dict.newSite.connOkAuth, {
            name: json.siteName ? ` (${json.siteName})` : "",
            pages: json.pagesCount ?? "?",
            media: json.mediaCount ?? "?",
            types: json.postTypes.join(", ") || "—",
          }),
        });
      } else if (json.ok) {
        setTestResult({ kind: "warn", text: fmt(dict.newSite.connOkRead, { err: json.error || "" }) });
      } else {
        setTestResult({ kind: "err", text: fmt(dict.newSite.connFail, { err: json.error || "" }) });
      }
    } catch (err) {
      setTestResult({ kind: "err", text: fmt(dict.newSite.connFail, { err: (err as Error).message }) });
    } finally {
      setTesting(false);
    }
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const res = await fetch("/api/sites", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: form.name.trim(),
          language: form.language,
          sitemapUrl: form.sitemapUrl.trim() || undefined,
          brandColors: { primary: form.primary, secondary: form.secondary, accent: form.accent },
          fontStyle: form.fontStyle,
          ...credentialPayload(),
        }),
      });
      const json = await res.json();
      if (!res.ok) throw new Error(json.error || `HTTP ${res.status}`);
      const siteId = json.site.id as string;

      if (form.sitemapUrl.trim()) {
        setStatusMsg(dict.newSite.ingestingMsg);
        await fetch(`/api/sites/${siteId}/ingest`, { method: "POST" }).catch(() => undefined);
      }
      setStatusMsg(dict.newSite.created);
      router.push(`/sites/${siteId}/sync`);
    } catch (err) {
      setError((err as Error).message);
      setSaving(false);
    }
  }

  return (
    <form onSubmit={submit} className="space-y-5">
      <Card>
        <CardHeader>
          <CardTitle>{dict.newSite.cardSite}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>{dict.newSite.name}</Label>
            <Input required value={form.name} onChange={set("name")} placeholder="Blondeau Déménagement" />
          </div>
          <div>
            <Label>{dict.newSite.domain}</Label>
            <Input required value={form.domain} onChange={set("domain")} placeholder="blondeau-demenagement.fr" />
          </div>
          <div>
            <Label>{dict.newSite.language}</Label>
            <Select value={form.language} onChange={set("language")} className="w-full">
              <option value="fr">Français</option>
              <option value="en">English</option>
            </Select>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{dict.newSite.cardWp}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <Label>{dict.newSite.wpUrl}</Label>
              <Input value={form.wpApiUrl} onChange={set("wpApiUrl")} placeholder="https://site.com" />
            </div>
            <div>
              <Label>{dict.newSite.authMethod}</Label>
              <Select value={form.wpAuthMethod} onChange={set("wpAuthMethod")} className="w-full">
                <option value="app-password">{dict.newSite.methodAppPw}</option>
                <option value="jwt">{dict.newSite.methodJwt}</option>
                <option value="legacy">{dict.newSite.methodLegacy}</option>
              </Select>
            </div>
            {form.wpAuthMethod === "jwt" && (
              <div>
                <Label>{dict.newSite.jwtToken}</Label>
                <Input value={form.wpJwtToken} onChange={set("wpJwtToken")} placeholder="eyJ0eXAiOiJKV1QiLCJhbGciOi…" />
              </div>
            )}
            {form.wpAuthMethod === "app-password" && (
              <>
                <div>
                  <Label>{dict.newSite.appUser}</Label>
                  <Input value={form.wpAppUser} onChange={set("wpAppUser")} placeholder="admin" />
                </div>
                <div>
                  <Label>{dict.newSite.appPassword}</Label>
                  <Input value={form.wpAppPassword} onChange={set("wpAppPassword")} placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" />
                </div>
              </>
            )}
            {form.wpAuthMethod === "legacy" && (
              <div>
                <Label>{dict.newSite.legacyKey}</Label>
                <Input value={form.wpAuthToken} onChange={set("wpAuthToken")} />
              </div>
            )}
          </div>
          <div className="flex items-center gap-3">
            <Button type="button" variant="secondary" size="sm" onClick={testConnection} disabled={testing}>
              {testing ? dict.newSite.testing : dict.newSite.testConn}
            </Button>
            {testResult && (
              <span
                className={
                  testResult.kind === "ok"
                    ? "text-xs text-emerald-400"
                    : testResult.kind === "warn"
                      ? "text-xs text-amber-400"
                      : "text-xs text-red-400"
                }
              >
                {testResult.text}
              </span>
            )}
          </div>
        </CardContent>
      </Card>

      <details className="group">
        <summary className="cursor-pointer text-sm text-zinc-400 hover:text-zinc-200">
          {dict.newSite.cardOptional}
        </summary>
        <Card className="mt-3">
          <CardContent className="grid gap-3 p-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <Label>{dict.newSite.sitemapUrl}</Label>
              <Input value={form.sitemapUrl} onChange={set("sitemapUrl")} placeholder="https://site.com/sitemap.xml" />
            </div>
            {(
              [
                ["primary", dict.newSite.primary],
                ["secondary", dict.newSite.secondary],
                ["accent", dict.newSite.accent],
              ] as const
            ).map(([key, label]) => (
              <div key={key} className="flex items-center gap-2">
                <Label className="mb-0 w-24">{label}</Label>
                <input
                  type="color"
                  value={form[key]}
                  onChange={set(key)}
                  className="h-8 w-12 cursor-pointer rounded border border-zinc-700 bg-zinc-900"
                />
                <span className="font-mono text-xs text-zinc-500">{form[key]}</span>
              </div>
            ))}
            <div className="flex items-center gap-2">
              <Label className="mb-0 w-24">{dict.newSite.fontStyle}</Label>
              <Select value={form.fontStyle} onChange={set("fontStyle")}>
                <option value="sans">sans</option>
                <option value="serif">serif</option>
                <option value="mono">mono</option>
              </Select>
            </div>
          </CardContent>
        </Card>
      </details>

      {statusMsg && (
        <div className="rounded-md border border-sky-800 bg-sky-950/30 p-2.5 text-sm text-sky-300">{statusMsg}</div>
      )}
      {error && <div className="rounded-md border border-red-800 bg-red-950/30 p-2.5 text-sm text-red-300">{error}</div>}

      <Button type="submit" disabled={saving} size="lg">
        {saving ? dict.newSite.saving : dict.newSite.submit}
      </Button>
    </form>
  );
}
