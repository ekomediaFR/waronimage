"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Input, Label } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useI18n } from "./LanguageProvider";

/** New-site form: domain + sitemap + brand config, then triggers sitemap ingestion. */
export function SitemapIngester() {
  const { dict } = useI18n();
  const router = useRouter();
  const [form, setForm] = useState({
    name: "",
    domain: "",
    sitemapUrl: "",
    primary: "#111827",
    secondary: "#f59e0b",
    accent: "#dc2626",
    fontStyle: "sans",
    wpApiUrl: "",
    wpAuthToken: "",
  });
  const [state, setState] = useState<"idle" | "saving" | "ingesting" | "error">("idle");
  const [message, setMessage] = useState("");

  function set<K extends keyof typeof form>(key: K, value: string) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setState("saving");
    setMessage("");
    try {
      const res = await fetch("/api/sites", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: form.name,
          domain: form.domain.replace(/^https?:\/\//, "").replace(/\/$/, ""),
          sitemapUrl: form.sitemapUrl,
          brandColors: { primary: form.primary, secondary: form.secondary, accent: form.accent },
          fontStyle: form.fontStyle,
          wpApiUrl: form.wpApiUrl || undefined,
          wpAuthToken: form.wpAuthToken || undefined,
        }),
      });
      if (!res.ok) throw new Error((await res.json()).error || `HTTP ${res.status}`);
      const { site } = await res.json();

      setState("ingesting");
      setMessage(dict.newSite.ingestingMsg);
      const ingestRes = await fetch(`/api/sites/${site.id}/ingest`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ maxPages: 300 }),
      });
      const ingest = await ingestRes.json();
      if (!ingestRes.ok) throw new Error(ingest.error || `Ingest failed (HTTP ${ingestRes.status})`);
      router.push(`/sites/${site.id}/pages`);
    } catch (err) {
      setState("error");
      setMessage((err as Error).message);
    }
  }

  const busy = state === "saving" || state === "ingesting";

  return (
    <form onSubmit={submit} className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>{dict.newSite.cardSite}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>{dict.newSite.name}</Label>
            <Input required value={form.name} onChange={(e) => set("name", e.target.value)} placeholder="Demenagement Paris 15" />
          </div>
          <div>
            <Label>{dict.newSite.domain}</Label>
            <Input required value={form.domain} onChange={(e) => set("domain", e.target.value)} placeholder="example.fr" />
          </div>
          <div className="sm:col-span-2">
            <Label>{dict.newSite.sitemapUrl}</Label>
            <Input
              required
              type="url"
              value={form.sitemapUrl}
              onChange={(e) => set("sitemapUrl", e.target.value)}
              placeholder="https://example.fr/sitemap_index.xml"
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{dict.newSite.cardBrand}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-4">
          <div>
            <Label>{dict.newSite.primary}</Label>
            <Input type="color" value={form.primary} onChange={(e) => set("primary", e.target.value)} className="h-9 p-1" />
          </div>
          <div>
            <Label>{dict.newSite.secondary}</Label>
            <Input type="color" value={form.secondary} onChange={(e) => set("secondary", e.target.value)} className="h-9 p-1" />
          </div>
          <div>
            <Label>{dict.newSite.accent}</Label>
            <Input type="color" value={form.accent} onChange={(e) => set("accent", e.target.value)} className="h-9 p-1" />
          </div>
          <div>
            <Label>{dict.newSite.fontStyle}</Label>
            <select
              value={form.fontStyle}
              onChange={(e) => set("fontStyle", e.target.value)}
              className="h-9 w-full rounded-md border border-zinc-700 bg-zinc-900 px-2 text-sm text-zinc-100"
            >
              <option value="sans">Sans</option>
              <option value="serif">Serif</option>
              <option value="mono">Mono</option>
            </select>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{dict.newSite.cardWp}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>{dict.newSite.wpApiUrl}</Label>
            <Input value={form.wpApiUrl} onChange={(e) => set("wpApiUrl", e.target.value)} placeholder="https://example.fr/wp-json" />
          </div>
          <div>
            <Label>{dict.newSite.wpToken}</Label>
            <Input value={form.wpAuthToken} onChange={(e) => set("wpAuthToken", e.target.value)} placeholder="eyJ… or admin:xxxx xxxx" />
          </div>
        </CardContent>
      </Card>

      <div className="flex items-center gap-3">
        <Button type="submit" disabled={busy}>
          {state === "saving" ? dict.newSite.saving : state === "ingesting" ? dict.newSite.ingesting : dict.newSite.submit}
        </Button>
        {message && (
          <p className={state === "error" ? "text-sm text-red-400" : "text-sm text-zinc-400"}>{message}</p>
        )}
      </div>
    </form>
  );
}
