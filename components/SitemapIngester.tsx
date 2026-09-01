"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Input, Label } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

/** New-site form: domain + sitemap + brand config, then triggers sitemap ingestion. */
export function SitemapIngester() {
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
      setMessage("Site saved. Reading sitemap and analyzing pages — this can take a few minutes…");
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
          <CardTitle>Site</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Name</Label>
            <Input required value={form.name} onChange={(e) => set("name", e.target.value)} placeholder="Demenagement Paris 15" />
          </div>
          <div>
            <Label>Domain</Label>
            <Input required value={form.domain} onChange={(e) => set("domain", e.target.value)} placeholder="example.fr" />
          </div>
          <div className="sm:col-span-2">
            <Label>Sitemap URL</Label>
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
          <CardTitle>Brand config (injected into AI prompts)</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-4">
          <div>
            <Label>Primary</Label>
            <Input type="color" value={form.primary} onChange={(e) => set("primary", e.target.value)} className="h-9 p-1" />
          </div>
          <div>
            <Label>Secondary</Label>
            <Input type="color" value={form.secondary} onChange={(e) => set("secondary", e.target.value)} className="h-9 p-1" />
          </div>
          <div>
            <Label>Accent</Label>
            <Input type="color" value={form.accent} onChange={(e) => set("accent", e.target.value)} className="h-9 p-1" />
          </div>
          <div>
            <Label>Font style</Label>
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
          <CardTitle>WordPress export (optional)</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>WP REST base URL</Label>
            <Input value={form.wpApiUrl} onChange={(e) => set("wpApiUrl", e.target.value)} placeholder="https://example.fr/wp-json" />
          </div>
          <div>
            <Label>Auth token (JWT or user:app-password)</Label>
            <Input value={form.wpAuthToken} onChange={(e) => set("wpAuthToken", e.target.value)} placeholder="eyJ… or admin:xxxx xxxx" />
          </div>
        </CardContent>
      </Card>

      <div className="flex items-center gap-3">
        <Button type="submit" disabled={busy}>
          {state === "saving" ? "Saving…" : state === "ingesting" ? "Ingesting sitemap…" : "Add site & ingest sitemap"}
        </Button>
        {message && (
          <p className={state === "error" ? "text-sm text-red-400" : "text-sm text-zinc-400"}>{message}</p>
        )}
      </div>
    </form>
  );
}
