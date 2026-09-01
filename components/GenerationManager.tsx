"use client";

import { useCallback, useEffect, useState } from "react";
import { StyleLibrary } from "./StyleLibrary";
import { GenerationQueue } from "./GenerationQueue";
import { ImageCard } from "./ImageCard";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import type { PageDto } from "@/lib/types";
import { useI18n } from "./LanguageProvider";
import { fmt } from "@/lib/i18n";

/** AI generation view: style library | pages needing images | queue monitor. */
export function GenerationManager({ siteId }: { siteId: string }) {
  const { dict } = useI18n();
  const [pages, setPages] = useState<PageDto[]>([]);
  const [styleId, setStyleId] = useState<string | null>(null);
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const [generating, setGenerating] = useState(false);

  const load = useCallback(async () => {
    const res = await fetch(`/api/sites/${siteId}/pages`);
    if (res.ok) setPages((await res.json()).pages);
  }, [siteId]);

  useEffect(() => {
    load();
  }, [load]);

  const needing = pages.filter((p) => p.aiImages.filter((i) => i.status !== "rejected").length === 0);
  const withImages = pages.filter((p) => p.aiImages.length > 0);

  function toggle(id: string) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  async function generate(ids: string[]) {
    if (!ids.length) return;
    setBusy(true);
    setGenerating(true);
    setNotice(fmt(dict.gen.launching, { n: ids.length }));
    const res = await fetch("/api/generate", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ pageIds: ids, styleId: styleId || undefined }),
    });
    const data = await res.json();
    if (!res.ok) setNotice(data.error || dict.gen.genFailed);
    else if (data.mode === "queued") setNotice(fmt(dict.gen.queued, { n: data.count }));
    else setNotice(data.note || fmt(dict.gen.inlineOk, { n: data.results?.filter((r: { ok: boolean }) => r.ok).length ?? 0 }));
    setBusy(false);
    setSelectedIds(new Set());
    load();
    setTimeout(() => setGenerating(false), 60000);
  }

  return (
    <div className="grid gap-4 xl:grid-cols-[300px_1fr_280px]">
      <div>
        <StyleLibrary selectedStyleId={styleId} onSelect={setStyleId} />
      </div>

      <div className="space-y-5">
        <section>
          <div className="mb-2 flex items-center justify-between">
            <h3 className="text-xs font-semibold uppercase tracking-wider text-zinc-500">
              {fmt(dict.gen.needing, { n: needing.length })}
            </h3>
            <div className="flex gap-2">
              <Button size="sm" variant="secondary" disabled={busy || !selectedIds.size} onClick={() => generate([...selectedIds])}>
                {fmt(dict.gen.generateSelected, { n: selectedIds.size })}
              </Button>
              <Button size="sm" disabled={busy || !needing.length} onClick={() => generate(needing.map((p) => p.id))}>
                {dict.gen.generateAll}
              </Button>
            </div>
          </div>
          {notice && <p className="mb-2 text-xs text-amber-400">{notice}</p>}
          <div className="max-h-80 space-y-1.5 overflow-y-auto rounded-lg border border-zinc-800 p-2">
            {needing.map((p) => (
              <label key={p.id} className="flex cursor-pointer items-center gap-2 rounded-md p-1.5 hover:bg-zinc-950">
                <input
                  type="checkbox"
                  checked={selectedIds.has(p.id)}
                  onChange={() => toggle(p.id)}
                  className="accent-amber-500"
                />
                <span className="min-w-0 flex-1 truncate text-sm text-zinc-300">{p.title}</span>
                <Badge>{p.niche}</Badge>
                {p.city && <Badge>{p.city}</Badge>}
              </label>
            ))}
            {!needing.length && <p className="p-3 text-xs text-zinc-600">{dict.gen.allCovered}</p>}
          </div>
        </section>

        <section>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">
            {dict.gen.variants}
          </h3>
          <div className="space-y-4">
            {withImages.slice(0, 10).map((p) => (
              <div key={p.id}>
                <p className="mb-1.5 truncate text-sm font-medium text-zinc-200">{p.title}</p>
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                  {p.aiImages.map((img) => (
                    <ImageCard key={img.id} image={img} onChanged={load} />
                  ))}
                </div>
              </div>
            ))}
            {!withImages.length && <p className="text-xs text-zinc-600">{dict.gen.nothing}</p>}
          </div>
        </section>
      </div>

      <div>
        <GenerationQueue siteId={siteId} active={generating} />
      </div>
    </div>
  );
}
