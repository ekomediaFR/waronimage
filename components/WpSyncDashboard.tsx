"use client";

// Sync & Match tab — the four workflow actions, live stats, the synced pages
// table (with each page's matched image) and the media library grid.

import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { MatchScoreBadge } from "@/components/MatchScoreBadge";
import { SyncButton } from "@/components/SyncButton";
import { useI18n } from "@/components/LanguageProvider";
import { fmt } from "@/lib/i18n";
import type { WpConnectionTestDto, WpOverviewDto } from "@/lib/types";

export function WpSyncDashboard({ siteId, hasCreds }: { siteId: string; hasCreds: boolean }) {
  const { dict } = useI18n();
  const [data, setData] = useState<WpOverviewDto | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pageFilter, setPageFilter] = useState("");
  const [mediaFilter, setMediaFilter] = useState("");
  const [testing, setTesting] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await fetch(`/api/sites/${siteId}/wp-overview`, { cache: "no-store" });
      if (!res.ok) throw new Error("load failed");
      setData((await res.json()) as WpOverviewDto);
      setLoadError(false);
    } catch {
      setLoadError(true);
    }
  }, [siteId]);

  useEffect(() => {
    load();
  }, [load]);

  function actionDone(getMessage: (json: Record<string, unknown>) => string) {
    return (json: Record<string, unknown>, ok: boolean) => {
      if (ok) {
        setError(null);
        setMessage(getMessage(json));
      } else {
        setMessage(null);
        setError(String(json?.error || "Error"));
      }
      load();
    };
  }

  async function testConnection() {
    setTesting(true);
    setMessage(null);
    setError(null);
    try {
      const res = await fetch(`/api/sites/${siteId}/test-connection`, { method: "POST" });
      const json = (await res.json()) as WpConnectionTestDto;
      if (json.ok && json.authenticated) setMessage(dict.syncTab.connOkAuth);
      else if (json.ok) setMessage(fmt(dict.syncTab.connOkRead, { err: json.error || "" }));
      else setError(fmt(dict.syncTab.connFail, { err: json.error || "" }));
    } catch (err) {
      setError(fmt(dict.syncTab.connFail, { err: (err as Error).message }));
    } finally {
      setTesting(false);
    }
  }

  const filteredPages = useMemo(() => {
    if (!data) return [];
    const q = pageFilter.toLowerCase();
    return q
      ? data.pages.filter(
          (p) => p.title.toLowerCase().includes(q) || p.slug.includes(q) || p.wpType.includes(q)
        )
      : data.pages;
  }, [data, pageFilter]);

  const filteredMedia = useMemo(() => {
    if (!data) return [];
    const q = mediaFilter.toLowerCase();
    return q ? data.media.filter((m) => m.filename.toLowerCase().includes(q)) : data.media;
  }, [data, mediaFilter]);

  const stats = data?.stats;

  return (
    <div className="space-y-5">
      <p className="text-sm text-zinc-400">{dict.syncTab.intro}</p>
      {!hasCreds && (
        <div className="rounded-md border border-amber-800 bg-amber-950/20 p-3 text-sm text-amber-300">
          {dict.syncTab.noCreds}
        </div>
      )}

      {/* Action bar */}
      <div className="flex flex-wrap items-center gap-2">
        <SyncButton
          endpoint={`/api/sites/${siteId}/sync/pages`}
          label={`↓ ${dict.syncTab.syncPages}`}
          loadingLabel={dict.syncTab.syncingPages}
          onDone={actionDone((j) => fmt(dict.syncTab.pagesSynced, { n: Number(j.synced ?? 0) }))}
        />
        <SyncButton
          endpoint={`/api/sites/${siteId}/sync/media`}
          label={`↓ ${dict.syncTab.syncMedia}`}
          loadingLabel={dict.syncTab.syncingMedia}
          onDone={actionDone((j) => fmt(dict.syncTab.mediaSynced, { n: Number(j.synced ?? 0) }))}
        />
        <SyncButton
          endpoint={`/api/sites/${siteId}/match`}
          label={`⚡ ${dict.syncTab.runMatching}`}
          loadingLabel={dict.syncTab.matching}
          onDone={actionDone((j) =>
            fmt(dict.syncTab.matchResult, {
              n: Number(j.matched ?? 0),
              a: Number(j.autoApproved ?? 0),
              m: Number(j.skippedManual ?? 0),
            })
          )}
        />
        <Link href={`/sites/${siteId}/wp-export`}>
          <Button variant="success">🚀 {dict.syncTab.exportApproved}</Button>
        </Link>
        <Button variant="ghost" size="sm" onClick={testConnection} disabled={testing} className="ml-auto">
          {testing ? dict.syncTab.testing : dict.syncTab.testConn}
        </Button>
      </div>

      {message && <div className="rounded-md border border-emerald-800 bg-emerald-950/30 p-2.5 text-sm text-emerald-300">{message}</div>}
      {error && <div className="rounded-md border border-red-800 bg-red-950/30 p-2.5 text-sm text-red-300">{error}</div>}
      {loadError && <div className="rounded-md border border-amber-800 bg-amber-950/20 p-2.5 text-sm text-amber-300">{dict.syncTab.loadFailed}</div>}

      {/* Stats row */}
      {stats && (
        <div className="grid grid-cols-3 gap-3 sm:grid-cols-6">
          {(
            [
              [dict.syncTab.statPages, stats.pagesCount],
              [dict.syncTab.statMedia, stats.mediaCount],
              [dict.syncTab.statAssigned, stats.assigned],
              [dict.syncTab.statApproved, stats.approved],
              [dict.syncTab.statExported, stats.exported],
              [dict.syncTab.statPending, stats.pending],
            ] as [string, number][]
          ).map(([label, value]) => (
            <Card key={label}>
              <CardContent className="p-3 text-center">
                <div className="text-xl font-bold text-zinc-100">{value}</div>
                <div className="mt-0.5 text-[11px] uppercase tracking-wide text-zinc-500">{label}</div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Two panels */}
      <div className="grid gap-4 lg:grid-cols-2">
        {/* Pages table */}
        <Card>
          <CardHeader className="flex-row items-center justify-between space-y-0">
            <CardTitle>{fmt(dict.syncTab.pagesPanel, { n: filteredPages.length })}</CardTitle>
            <Input
              placeholder={dict.syncTab.searchPages}
              value={pageFilter}
              onChange={(e) => setPageFilter(e.target.value)}
              className="h-8 w-44"
            />
          </CardHeader>
          <CardContent className="max-h-[560px] overflow-y-auto p-0">
            {filteredPages.length === 0 ? (
              <p className="p-4 text-sm text-zinc-500">{dict.syncTab.noPages}</p>
            ) : (
              <table className="w-full text-left text-sm">
                <thead className="sticky top-0 bg-white text-[11px] uppercase tracking-wide text-zinc-500">
                  <tr>
                    <th className="px-3 py-2">{dict.syncTab.thPage}</th>
                    <th className="px-2 py-2">{dict.syncTab.thType}</th>
                    <th className="px-2 py-2">{dict.syncTab.thMatch}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-800/60">
                  {filteredPages.map((p) => (
                    <tr key={p.id} className="align-middle">
                      <td className="max-w-[220px] px-3 py-1.5">
                        <a href={p.url} target="_blank" rel="noreferrer" className="block truncate text-zinc-200 hover:text-amber-400" title={p.title}>
                          {p.title || p.slug}
                        </a>
                        <span className="block truncate text-[11px] text-zinc-500">/{p.slug}</span>
                      </td>
                      <td className="px-2 py-1.5">
                        <Badge>{p.wpType}</Badge>
                        {p.status !== "publish" && <Badge variant="yellow" className="ml-1">{p.status}</Badge>}
                      </td>
                      <td className="px-2 py-1.5">
                        {p.assignment ? (
                          <span className="flex items-center gap-2">
                            {/* eslint-disable-next-line @next/next/no-img-element */}
                            <img
                              src={p.assignment.media.thumbUrl || p.assignment.media.sourceUrl}
                              alt=""
                              loading="lazy"
                              className="h-8 w-14 rounded object-cover"
                            />
                            <MatchScoreBadge score={p.assignment.matchScore} />
                            {p.assignment.exported ? (
                              <Badge variant="green">✓</Badge>
                            ) : p.assignment.approved ? (
                              <Badge variant="blue">✓</Badge>
                            ) : null}
                          </span>
                        ) : (
                          <span className="text-xs text-zinc-600">—</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>

        {/* Media grid */}
        <Card>
          <CardHeader className="flex-row items-center justify-between space-y-0">
            <CardTitle>{fmt(dict.syncTab.mediaPanel, { n: filteredMedia.length })}</CardTitle>
            <Input
              placeholder={dict.syncTab.searchMedia}
              value={mediaFilter}
              onChange={(e) => setMediaFilter(e.target.value)}
              className="h-8 w-44"
            />
          </CardHeader>
          <CardContent className="max-h-[560px] overflow-y-auto">
            {filteredMedia.length === 0 ? (
              <p className="text-sm text-zinc-500">{dict.syncTab.noMedia}</p>
            ) : (
              <div className="grid grid-cols-3 gap-2 xl:grid-cols-4">
                {filteredMedia.map((m) => (
                  <div key={m.id} className="group relative overflow-hidden rounded-md border border-zinc-800">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img
                      src={m.thumbUrl || m.sourceUrl}
                      alt={m.filename}
                      loading="lazy"
                      className="aspect-video w-full object-cover"
                    />
                    <div className="truncate bg-zinc-950/80 px-1.5 py-1 text-[10px] text-zinc-400" title={m.filename}>
                      {m.filename}
                    </div>
                    <div className="absolute right-1 top-1 flex gap-1">
                      {!m.isUsable && <Badge variant="red">{dict.syncTab.unusable}</Badge>}
                      {(m.usageCount ?? 0) > 0 && (
                        <Badge variant="blue">{fmt(dict.syncTab.usedTimes, { n: m.usageCount ?? 0 })}</Badge>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Last operations */}
      {data && data.lastLogs.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>{dict.syncTab.lastOps}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-1 text-xs text-zinc-400">
            {data.lastLogs.map((log) => (
              <div key={log.id} className="flex items-center gap-2">
                <Badge
                  variant={log.status === "done" ? "green" : log.status === "error" ? "red" : "yellow"}
                >
                  {log.status}
                </Badge>
                <span className="font-medium text-zinc-300">{log.type}</span>
                <span>
                  {[
                    log.pagesCount != null ? `${log.pagesCount} pages` : null,
                    log.mediaCount != null ? `${log.mediaCount} media` : null,
                    log.matchCount != null ? `${log.matchCount} match` : null,
                    log.exportCount != null ? `${log.exportCount} export` : null,
                  ]
                    .filter(Boolean)
                    .join(" · ")}
                </span>
                {log.errorMsg && <span className="truncate text-red-400">{log.errorMsg}</span>}
                <span className="ml-auto text-zinc-600">{new Date(log.startedAt).toLocaleString()}</span>
              </div>
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
