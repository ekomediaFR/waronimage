"use client";

import { useCallback, useEffect, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input, Label, Textarea } from "@/components/ui/input";
import { useI18n } from "./LanguageProvider";
import { fmt } from "@/lib/i18n";
import type { WpPluginImage, WpPluginInfo } from "@/lib/wpPlugin";

interface MediaResponse {
  connected: boolean;
  reason?: string;
  error?: string;
  info?: WpPluginInfo;
  images?: WpPluginImage[];
  total?: number;
  total_pages?: number;
  page?: number;
}

/** WordPress media library — direct access via the War on Image Connect plugin. */
export function WpLibrary({ siteId, initialWpApiUrl }: { siteId: string; initialWpApiUrl: string }) {
  const { dict } = useI18n();
  const [data, setData] = useState<MediaResponse | null>(null);
  const [page, setPage] = useState(1);
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [key, setKey] = useState("");
  const [apiUrl, setApiUrl] = useState(initialWpApiUrl);

  const load = useCallback(async (p: number) => {
    const res = await fetch(`/api/sites/${siteId}/wp/media?page=${p}`);
    if (res.ok) setData(await res.json());
  }, [siteId]);

  useEffect(() => {
    load(page);
  }, [load, page]);

  async function saveConnection(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setNotice(dict.wpLib.checking);
    await fetch(`/api/sites/${siteId}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ wpAuthToken: key, ...(apiUrl ? { wpApiUrl: apiUrl } : {}) }),
    });
    await load(1);
    setBusy(false);
    setNotice("");
  }

  async function syncContent() {
    setBusy(true);
    setNotice(dict.wpLib.syncing);
    const res = await fetch(`/api/sites/${siteId}/wp/sync`, { method: "POST" });
    const d = await res.json();
    setNotice(res.ok ? fmt(dict.wpLib.syncResult, { matched: d.matched, pages: d.pages, total: d.totalWp }) : d.error);
    setBusy(false);
  }

  if (!data) return <p className="text-sm text-zinc-500">{dict.common.loading}</p>;

  if (!data.connected) {
    return (
      <div className="max-w-2xl space-y-4">
        <Card className="border-amber-800 bg-amber-950/30">
          <CardContent className="p-4 text-sm text-amber-300">
            {data.reason === "outdated" ? dict.wpLib.outdated : dict.wpLib.notConnected}
            {data.reason === "unreachable" && <span> {dict.wpLib.unreachable}</span>}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>EkoSEO Bridge</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3 text-sm text-zinc-300">
            <p>{dict.wpLib.intro}</p>
            <ol className="list-decimal space-y-2 pl-5">
              <li>
                {dict.wpLib.step1}{" "}
                <a href="/ekoseo-bridge-1.5.0.zip" download className="font-medium text-amber-400 hover:underline">
                  {dict.wpLib.download}
                </a>
              </li>
              <li>{dict.wpLib.step2}</li>
              <li>{dict.wpLib.step3}</li>
              <li>{dict.wpLib.step4}</li>
            </ol>
            <form onSubmit={saveConnection} className="space-y-3 pt-2">
              <div>
                <Label>{dict.wpLib.keyLabel}</Label>
                <Input required value={key} onChange={(e) => setKey(e.target.value)} placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" />
              </div>
              <div>
                <Label>{dict.newSite.wpApiUrl}</Label>
                <Input value={apiUrl} onChange={(e) => setApiUrl(e.target.value)} placeholder="https://example.fr/wp-json" />
              </div>
              <div className="flex items-center gap-3">
                <Button type="submit" disabled={busy}>{dict.wpLib.save}</Button>
                {notice && <span className="text-xs text-zinc-400">{notice}</span>}
              </div>
            </form>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Badge variant="green">
          {fmt(dict.wpLib.connectedTo, { site: data.info?.site_name ?? "", wp: data.info?.wp_version ?? "", pv: data.info?.plugin_version ?? "" })}
        </Badge>
        <span className="text-sm text-zinc-400">{fmt(dict.wpLib.imagesCount, { n: data.total ?? 0 })}</span>
        <div className="ml-auto flex items-center gap-2">
          <Button size="sm" variant="secondary" disabled={busy} onClick={syncContent}>
            {busy ? dict.wpLib.syncing : dict.wpLib.syncContent}
          </Button>
          <Button size="sm" variant="ghost" onClick={() => load(page)}>{dict.wpLib.refresh}</Button>
        </div>
      </div>
      {notice && <p className="text-xs text-amber-400">{notice}</p>}

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {(data.images ?? []).map((img) => (
          <WpImageCard key={img.id} siteId={siteId} image={img} onSaved={() => load(page)} />
        ))}
        {!data.images?.length && <p className="col-span-full py-8 text-center text-sm text-zinc-500">{dict.wpLib.noImages}</p>}
      </div>

      {(data.total_pages ?? 1) > 1 && (
        <div className="flex items-center justify-center gap-3">
          <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            {dict.wpLib.prev}
          </Button>
          <span className="text-xs text-zinc-400">{fmt(dict.wpLib.pageOf, { p: data.page ?? page, t: data.total_pages ?? 1 })}</span>
          <Button size="sm" variant="secondary" disabled={page >= (data.total_pages ?? 1)} onClick={() => setPage((p) => p + 1)}>
            {dict.wpLib.next}
          </Button>
        </div>
      )}
    </div>
  );
}

function WpImageCard({ siteId, image, onSaved }: { siteId: string; image: WpPluginImage; onSaved: () => void }) {
  const { dict } = useI18n();
  const [editing, setEditing] = useState(false);
  const [copied, setCopied] = useState(false);
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState({
    alt: image.alt,
    title: image.title,
    caption: image.caption,
    description: image.description,
  });

  async function save() {
    setBusy(true);
    await fetch(`/api/sites/${siteId}/wp/media`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ imageId: image.id, ...form }),
    });
    setBusy(false);
    setEditing(false);
    onSaved();
  }

  function copyUrl() {
    navigator.clipboard?.writeText(image.url).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  }

  const thumb = image.sizes?.medium?.url || image.sizes?.thumbnail?.url || image.url;
  const sizeKb = image.filesize ? `${Math.round(image.filesize / 1024)} KB` : "—";

  return (
    <div className="overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src={thumb} alt={image.alt || image.filename} className="aspect-video w-full bg-zinc-950 object-cover" />
      <div className="space-y-2 p-3 text-xs">
        <p className="truncate font-mono text-[11px] text-zinc-500" title={image.filename}>#{image.id} · {image.filename}</p>
        <div className="flex flex-wrap gap-1.5">
          <Badge>{image.extension || image.mime_type}</Badge>
          <Badge>{image.width ?? "?"}×{image.height ?? "?"}</Badge>
          <Badge>{sizeKb}</Badge>
          {image.attached_to && <Badge variant="blue">{fmt(dict.wpLib.attachedTo, { id: image.attached_to })}</Badge>}
        </div>
        <p className="line-clamp-2 text-zinc-400" title={image.alt}>
          <span className="font-semibold text-zinc-500">{dict.meta.alt}: </span>
          {image.alt || "—"}
        </p>
        {image.description && (
          <p className="line-clamp-2 text-zinc-500" title={image.description}>{image.description}</p>
        )}
        <div className="flex gap-1.5">
          <Button size="sm" variant="secondary" onClick={copyUrl}>
            {copied ? dict.wpLib.copied : dict.wpLib.copyUrl}
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setEditing((e) => !e)}>
            {editing ? dict.common.close : dict.common.edit}
          </Button>
        </div>
        {editing && (
          <div className="space-y-2 rounded-md border border-zinc-800 bg-zinc-950 p-2.5">
            <div>
              <Label>{dict.meta.alt}</Label>
              <Textarea value={form.alt} maxLength={125} onChange={(e) => setForm({ ...form, alt: e.target.value })} />
            </div>
            <div>
              <Label>{dict.meta.title}</Label>
              <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
            </div>
            <div>
              <Label>{dict.meta.caption}</Label>
              <Input value={form.caption} onChange={(e) => setForm({ ...form, caption: e.target.value })} />
            </div>
            <div>
              <Label>Description</Label>
              <Textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <Button size="sm" disabled={busy} onClick={save}>
              {busy ? dict.common.saving : dict.meta.save}
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}
