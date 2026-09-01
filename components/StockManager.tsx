"use client";

import { useCallback, useEffect, useState } from "react";
import { StockUploader } from "./StockUploader";
import { MatchScoreBadge } from "./MatchScoreBadge";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useAppStore } from "@/lib/store";
import type { StockImageDto } from "@/lib/types";
import { useI18n } from "./LanguageProvider";
import { fmt } from "@/lib/i18n";

interface PageMatch {
  page: { id: string; title: string; url: string; niche: string; city?: string | null };
  score: number;
}

/** Stock photo manager: upload → analyze → match → assign. */
export function StockManager({ siteId }: { siteId: string }) {
  const { dict } = useI18n();
  const { selectedStockImageId, selectStockImage } = useAppStore();
  const [images, setImages] = useState<StockImageDto[]>([]);
  const [statusFilter, setStatusFilter] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [matches, setMatches] = useState<PageMatch[]>([]);
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    const params = new URLSearchParams({ siteId });
    if (statusFilter) params.set("status", statusFilter);
    const res = await fetch(`/api/stock?${params}`);
    if (res.ok) setImages((await res.json()).images);
  }, [siteId, statusFilter]);

  useEffect(() => {
    load();
  }, [load]);

  const selected = images.find((i) => i.id === selectedStockImageId) || null;

  useEffect(() => {
    if (!selected) {
      setMatches([]);
      return;
    }
    fetch(`/api/stock/assign?siteId=${siteId}&imageId=${selected.id}`)
      .then((r) => (r.ok ? r.json() : { matches: [] }))
      .then((d) => setMatches(d.matches || []));
  }, [selected, siteId]);

  async function analyzeAll() {
    setBusy("analyze");
    setNotice(dict.stock.analyzingMsg);
    const res = await fetch("/api/stock/analyze", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ siteId }),
    });
    const data = await res.json();
    setNotice(res.ok ? fmt(dict.stock.analyzedMsg, { a: data.analyzed, b: data.total, f: data.failed }) : data.error);
    setBusy(null);
    load();
  }

  async function autoAssign() {
    setBusy("assign");
    setNotice(dict.stock.matchingMsg);
    const res = await fetch("/api/stock/assign", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ siteId, mode: "auto" }),
    });
    const data = await res.json();
    setNotice(res.ok ? fmt(dict.stock.assignedMsg, { n: data.assigned, s: data.strongMatches }) : data.error);
    setBusy(null);
    load();
  }

  async function assignManual(pageId: string) {
    if (!selected) return;
    setBusy("manual");
    await fetch("/api/stock/assign", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ siteId, mode: "manual", pageId, stockImageId: selected.id }),
    });
    setBusy(null);
    setNotice(dict.stock.assignedOne);
    load();
  }

  return (
    <div className="grid gap-4 xl:grid-cols-[280px_1fr_320px]">
      <div className="space-y-4">
        <StockUploader siteId={siteId} onUploaded={load} />
        <div className="space-y-2">
          <Button className="w-full" variant="secondary" disabled={busy !== null} onClick={analyzeAll}>
            {busy === "analyze" ? dict.stock.analyzing : dict.stock.analyze}
          </Button>
          <Button className="w-full" disabled={busy !== null} onClick={autoAssign}>
            {busy === "assign" ? dict.stock.assigning : dict.stock.autoAssign}
          </Button>
          {notice && <p className="text-xs text-amber-400">{notice}</p>}
        </div>
      </div>

      <div>
        <div className="mb-3 flex items-center justify-between">
          <p className="text-sm text-zinc-400">{fmt(dict.stock.photosCount, { n: images.length })}</p>
          <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="">{dict.stock.allStatuses}</option>
            <option value="unprocessed">{dict.status.unprocessed}</option>
            <option value="analyzed">{dict.status.analyzed}</option>
            <option value="assigned">{dict.status.assigned}</option>
          </Select>
        </div>
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-4">
          {images.map((img) => (
            <button
              key={img.id}
              onClick={() => selectStockImage(img.id === selectedStockImageId ? null : img.id)}
              className={`overflow-hidden rounded-lg border text-left transition-colors ${img.id === selectedStockImageId ? "border-amber-500" : "border-zinc-800 hover:border-zinc-600"}`}
            >
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={img.originalUrl} alt={img.altText || img.filename} className="aspect-[4/3] w-full object-cover" />
              <div className="space-y-1 p-2">
                <div className="flex flex-wrap gap-1">
                  <Badge
                    variant={img.status === "assigned" ? "green" : img.status === "analyzed" ? "blue" : "default"}
                  >
                    {dict.status[img.status] ?? img.status}
                  </Badge>
                  {img.niche && <Badge>{img.niche}</Badge>}
                  {typeof img.quality === "number" && <Badge variant="amber">Q{img.quality}</Badge>}
                </div>
                <p className="truncate text-[11px] text-zinc-500">{img.filename}</p>
              </div>
            </button>
          ))}
          {!images.length && (
            <p className="col-span-full py-10 text-center text-sm text-zinc-600">{dict.stock.empty}</p>
          )}
        </div>
      </div>

      <div>
        <Card>
          <CardHeader>
            <CardTitle>{selected ? dict.stock.matchedPages : dict.stock.selectPhoto}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {selected && (
              <>
                <p className="text-xs text-zinc-400">{selected.altText || selected.filename}</p>
                {selected.tags?.length > 0 && (
                  <div className="flex flex-wrap gap-1">
                    {selected.tags.slice(0, 8).map((t) => (
                      <Badge key={t}>{t}</Badge>
                    ))}
                  </div>
                )}
                <div className="space-y-2 pt-2">
                  {matches.map((m) => (
                    <div key={m.page.id} className="rounded-md border border-zinc-800 p-2">
                      <p className="truncate text-xs font-medium text-zinc-200">{m.page.title}</p>
                      <div className="mt-1 flex items-center justify-between gap-2">
                        <MatchScoreBadge score={m.score} />
                        <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => assignManual(m.page.id)}>
                          {dict.common.assign}
                        </Button>
                      </div>
                    </div>
                  ))}
                  {selected.status === "unprocessed" && (
                    <p className="text-xs text-zinc-600">{dict.stock.analyzeFirst}</p>
                  )}
                </div>
              </>
            )}
            {!selected && <p className="text-xs text-zinc-600">{dict.stock.clickHint}</p>}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
