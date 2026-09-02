"use client";

// Review tab — one split-panel card per assignment: page info on the left,
// matched image + score + editable SEO metadata on the right.

import { useCallback, useEffect, useState } from "react";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { MatchScoreBadge } from "@/components/MatchScoreBadge";
import { MetadataEditor, type SeoFields } from "@/components/MetadataEditor";
import { MediaPickerModal } from "@/components/MediaPickerModal";
import { useI18n } from "@/components/LanguageProvider";
import { fmt } from "@/lib/i18n";
import type { WpAssignmentDto, WpMediaLiteDto, WpOverviewDto } from "@/lib/types";
import { cn } from "@/lib/utils";

type Filter = "all" | "pending" | "approved" | "exported" | "errors";

export function WpReviewPanel({ siteId }: { siteId: string }) {
  const { dict } = useI18n();
  const [assignments, setAssignments] = useState<WpAssignmentDto[] | null>(null);
  const [filter, setFilter] = useState<Filter>("all");
  const [minScore, setMinScore] = useState(0);
  const [threshold, setThreshold] = useState(65);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [savedId, setSavedId] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [swapFor, setSwapFor] = useState<WpAssignmentDto | null>(null);
  const [mediaList, setMediaList] = useState<WpMediaLiteDto[] | null>(null);

  const load = useCallback(async () => {
    const params = new URLSearchParams();
    if (filter !== "all") params.set("status", filter);
    if (minScore > 0) params.set("minScore", String(minScore / 100));
    const res = await fetch(`/api/sites/${siteId}/assignments?${params}`, { cache: "no-store" });
    if (res.ok) {
      const json = await res.json();
      setAssignments(json.assignments as WpAssignmentDto[]);
    } else {
      setAssignments([]);
    }
  }, [siteId, filter, minScore]);

  useEffect(() => {
    load();
  }, [load]);

  async function patch(id: string, body: Record<string, unknown>) {
    setBusyId(id);
    try {
      await fetch(`/api/wp-assignments/${id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      await load();
    } finally {
      setBusyId(null);
    }
  }

  async function saveMeta(id: string, fields: SeoFields) {
    setBusyId(id);
    try {
      await fetch(`/api/wp-assignments/${id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(fields),
      });
      setSavedId(id);
      setTimeout(() => setSavedId((s) => (s === id ? null : s)), 2000);
      await load();
    } finally {
      setBusyId(null);
    }
  }

  async function removeAssignment(id: string) {
    setBusyId(id);
    try {
      await fetch(`/api/wp-assignments/${id}`, { method: "DELETE" });
      await load();
    } finally {
      setBusyId(null);
    }
  }

  async function approveAll() {
    const res = await fetch(`/api/sites/${siteId}/approve-all`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ threshold: threshold / 100 }),
    });
    const json = await res.json().catch(() => ({}));
    setMessage(fmt(dict.review.approveAllDone, { n: json.approved ?? 0 }));
    load();
  }

  async function openSwap(assignment: WpAssignmentDto) {
    setSwapFor(assignment);
    if (mediaList) return;
    // One retry — then surface the failure instead of silently doing nothing.
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        const res = await fetch(`/api/sites/${siteId}/wp-overview`, { cache: "no-store" });
        if (res.ok) {
          const json = (await res.json()) as WpOverviewDto;
          setMediaList(json.media);
          return;
        }
      } catch {
        /* retry */
      }
    }
    setSwapFor(null);
    setMessage(dict.syncTab.loadFailed);
  }

  const filters: { key: Filter; label: string }[] = [
    { key: "all", label: dict.review.filterAll },
    { key: "pending", label: dict.review.filterPending },
    { key: "approved", label: dict.review.filterApproved },
    { key: "exported", label: dict.review.filterExported },
    { key: "errors", label: dict.review.filterErrors },
  ];

  return (
    <div className="space-y-4">
      <p className="text-sm text-zinc-400">{dict.review.intro}</p>

      {/* Filter bar */}
      <div className="flex flex-wrap items-center gap-2">
        {filters.map((f) => (
          <button
            key={f.key}
            onClick={() => setFilter(f.key)}
            className={cn(
              "rounded-full border px-3 py-1 text-xs font-medium transition-colors",
              filter === f.key
                ? "border-amber-500 bg-amber-500/10 text-amber-400"
                : "border-zinc-700 text-zinc-400 hover:text-zinc-200"
            )}
          >
            {f.label}
          </button>
        ))}
        <label className="ml-2 flex items-center gap-2 text-xs text-zinc-400">
          {fmt(dict.review.minScore, { n: minScore })}
          <input
            type="range"
            min={0}
            max={100}
            step={5}
            value={minScore}
            onChange={(e) => setMinScore(Number(e.target.value))}
            className="accent-amber-500"
          />
        </label>
        <div className="ml-auto flex items-center gap-2">
          <input
            type="range"
            min={0}
            max={100}
            step={5}
            value={threshold}
            onChange={(e) => setThreshold(Number(e.target.value))}
            className="accent-emerald-500"
          />
          <Button size="sm" variant="success" onClick={approveAll}>
            {fmt(dict.review.approveAll, { n: threshold })}
          </Button>
        </div>
      </div>

      {message && (
        <div className="rounded-md border border-emerald-800 bg-emerald-950/30 p-2.5 text-sm text-emerald-300">{message}</div>
      )}
      {assignments && (
        <p className="text-xs text-zinc-500">{fmt(dict.review.count, { n: assignments.length })}</p>
      )}

      {/* Assignment cards */}
      {assignments === null ? (
        <p className="text-sm text-zinc-500">{dict.common.loading}</p>
      ) : assignments.length === 0 ? (
        <Card>
          <CardContent className="p-8 text-center text-sm text-zinc-500">{dict.review.empty}</CardContent>
        </Card>
      ) : (
        assignments.map((a) => (
          <Card key={a.id} className={cn(a.approved && "border-emerald-800/70")}>
            <CardContent className="grid gap-4 p-4 lg:grid-cols-2">
              {/* Left: page info */}
              <div className="space-y-1.5">
                <div className="flex items-center gap-2 text-[11px] uppercase tracking-wide text-zinc-500">
                  {dict.review.page}
                  <Badge>{a.page.wpType}</Badge>
                  {a.page.niche && <Badge variant="blue">{a.page.niche}</Badge>}
                  {a.page.city && <Badge variant="yellow">{a.page.city}</Badge>}
                </div>
                <h3 className="font-semibold text-zinc-100">{a.page.title || a.page.slug}</h3>
                <a
                  href={a.page.url}
                  target="_blank"
                  rel="noreferrer"
                  className="block truncate text-xs text-sky-400 hover:underline"
                >
                  {a.page.url}
                </a>
                <p className="text-xs text-zinc-500">
                  {a.page.currentFeaturedMediaId
                    ? fmt(dict.review.currentFeatured, { id: a.page.currentFeaturedMediaId })
                    : dict.review.noFeatured}
                </p>
                {a.matchReason && (
                  <p className="rounded bg-zinc-950/60 p-2 font-mono text-[10px] leading-relaxed text-zinc-500">
                    <span className="text-zinc-400">{dict.review.reason}:</span> {a.matchReason}
                  </p>
                )}
                {a.exportError && (
                  <p className="text-xs text-red-400">{fmt(dict.review.exportErr, { err: a.exportError })}</p>
                )}
              </div>

              {/* Right: matched media + actions */}
              <div className="space-y-2">
                <div className="flex items-center gap-2 text-[11px] uppercase tracking-wide text-zinc-500">
                  {dict.review.matched}
                  <MatchScoreBadge score={a.matchScore} />
                  <Badge variant={a.assignedBy === "manual" ? "yellow" : "default"}>
                    {a.assignedBy === "manual" ? dict.review.manual : dict.review.auto}
                  </Badge>
                  {a.exported && <Badge variant="green">{dict.review.exportedBadge}</Badge>}
                  {savedId === a.id && <span className="text-emerald-400 normal-case">{dict.review.saved}</span>}
                </div>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={a.media.thumbUrl || a.media.sourceUrl}
                  alt={a.media.wpAltText || a.media.filename}
                  loading="lazy"
                  className="aspect-video w-full max-w-md rounded-md border border-zinc-800 object-cover"
                />
                <p className="truncate font-mono text-[10px] text-zinc-500">{a.media.filename}</p>
                <div className="flex flex-wrap gap-2">
                  {a.approved ? (
                    <Button size="sm" variant="secondary" disabled={busyId === a.id} onClick={() => patch(a.id, { approved: false })}>
                      {dict.review.unapprove}
                    </Button>
                  ) : (
                    <Button size="sm" variant="success" disabled={busyId === a.id} onClick={() => patch(a.id, { approved: true })}>
                      {dict.review.approve}
                    </Button>
                  )}
                  <Button size="sm" variant="ghost" disabled={busyId === a.id} onClick={() => openSwap(a)}>
                    {dict.review.swap}
                  </Button>
                  <Button size="sm" variant="destructive" disabled={busyId === a.id} onClick={() => removeAssignment(a.id)}>
                    {dict.review.remove}
                  </Button>
                </div>
                <MetadataEditor
                  initial={{
                    seoAltText: a.seoAltText || "",
                    seoTitle: a.seoTitle || "",
                    seoCaption: a.seoCaption || "",
                    seoDescription: a.seoDescription || "",
                    seoFilename: a.seoFilename || "",
                  }}
                  saving={busyId === a.id}
                  onSave={(fields) => saveMeta(a.id, fields)}
                />
              </div>
            </CardContent>
          </Card>
        ))
      )}

      {swapFor && mediaList && (
        <MediaPickerModal
          media={mediaList.filter((m) => m.isUsable !== false)}
          currentMediaId={swapFor.mediaId}
          onClose={() => setSwapFor(null)}
          onSelect={async (mediaId) => {
            const id = swapFor.id;
            setSwapFor(null);
            await patch(id, { mediaId });
          }}
        />
      )}
    </div>
  );
}
