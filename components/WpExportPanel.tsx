"use client";

// WP Export tab — one command pushes every approved assignment to WordPress.
// Runs in batches of BATCH_SIZE so serverless timeouts can't kill a big export,
// and polls /export/status every 2 s while running so the bar moves live.

import { useCallback, useEffect, useRef, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { useI18n } from "@/components/LanguageProvider";
import { fmt } from "@/lib/i18n";
import type { ExportStatusDto } from "@/lib/types";

const BATCH_SIZE = 25;

export function WpExportPanel({ siteId, hasCreds }: { siteId: string; hasCreds: boolean }) {
  const { dict } = useI18n();
  const [status, setStatus] = useState<ExportStatusDto | null>(null);
  const [running, setRunning] = useState(false);
  const [result, setResult] = useState<{ success: number; failed: number } | null>(null);
  const [retryingId, setRetryingId] = useState<string | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const refresh = useCallback(async () => {
    const res = await fetch(`/api/sites/${siteId}/export/status`, { cache: "no-store" });
    if (res.ok) setStatus((await res.json()) as ExportStatusDto);
  }, [siteId]);

  useEffect(() => {
    refresh();
  }, [refresh]);

  // Poll while an export loop runs.
  useEffect(() => {
    if (running) {
      pollRef.current = setInterval(refresh, 2000);
      return () => {
        if (pollRef.current) clearInterval(pollRef.current);
      };
    }
  }, [running, refresh]);

  async function exportAll() {
    setRunning(true);
    setResult(null);
    let success = 0;
    try {
      // Batch loop: keep going until nothing is pending or a batch makes no progress.
      for (let i = 0; i < 200; i++) {
        const res = await fetch(`/api/sites/${siteId}/export`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ limit: BATCH_SIZE }),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) break;
        success += json.success ?? 0;
        if ((json.success ?? 0) + (json.failed ?? 0) === 0) break; // nothing left
        if ((json.success ?? 0) === 0) break; // only failures left — stop looping
      }
    } finally {
      setRunning(false);
      // Failures = assignments still carrying an export error, not per-batch
      // sums (a failing page would otherwise be counted once per batch).
      const res = await fetch(`/api/sites/${siteId}/export/status`, { cache: "no-store" });
      const json = res.ok ? ((await res.json()) as ExportStatusDto) : null;
      if (json) setStatus(json);
      setResult({ success, failed: json?.errors.length ?? 0 });
    }
  }

  async function retry(assignmentId: string) {
    setRetryingId(assignmentId);
    try {
      await fetch(`/api/wp-assignments/${assignmentId}/export`, { method: "POST" });
      await refresh();
    } finally {
      setRetryingId(null);
    }
  }

  const total = status?.approved ?? 0;
  const done = status?.exported ?? 0;
  const pending = status?.pending ?? 0;
  const percent = total > 0 ? Math.round((done / total) * 100) : 0;

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <p className="text-sm text-zinc-400">{dict.wpx.intro}</p>
      {!hasCreds && (
        <div className="rounded-md border border-amber-800 bg-amber-950/20 p-3 text-sm text-amber-300">
          {dict.wpx.noCreds}
        </div>
      )}

      <Card>
        <CardContent className="space-y-4 p-5">
          <div className="flex items-center gap-3">
            <div className="text-sm text-zinc-300">
              {pending > 0 ? fmt(dict.wpx.ready, { n: pending }) : total > 0 ? dict.wpx.allDone : dict.review.empty}
            </div>
            <Button
              className="ml-auto"
              variant="success"
              disabled={running || pending === 0 || !hasCreds}
              onClick={exportAll}
            >
              {running && (
                <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
              )}
              🚀 {running ? dict.wpx.exporting : dict.wpx.exportAll}
            </Button>
            <Button variant="ghost" size="sm" onClick={refresh}>
              {dict.wpx.refresh}
            </Button>
          </div>

          {/* Progress bar */}
          {total > 0 && (
            <div>
              <div className="mb-1 flex justify-between text-xs text-zinc-400">
                <span>{fmt(dict.wpx.progress, { done, total })}</span>
                <span>{percent}%</span>
              </div>
              <div className="h-2.5 overflow-hidden rounded-full bg-zinc-800">
                <div
                  className="h-full rounded-full bg-emerald-500 transition-all duration-500"
                  style={{ width: `${percent}%` }}
                />
              </div>
            </div>
          )}

          <p className="text-[11px] text-zinc-600">{fmt(dict.wpx.batchNote, { n: BATCH_SIZE })}</p>

          {result && (
            <div className="rounded-md border border-emerald-800 bg-emerald-950/30 p-2.5 text-sm text-emerald-300">
              {fmt(dict.wpx.resultDone, { s: result.success, f: result.failed })}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Errors with retry */}
      {status && status.errors.length > 0 && (
        <Card className="border-red-900/70">
          <CardHeader>
            <CardTitle>{fmt(dict.wpx.errorsTitle, { n: status.errors.length })}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {status.errors.map((e) => (
              <div key={e.assignmentId} className="flex items-start gap-2 text-xs">
                <Badge variant="red">✗</Badge>
                <div className="min-w-0 flex-1">
                  <p className="truncate font-medium text-zinc-200">{e.pageTitle}</p>
                  <p className="truncate text-red-400">{e.error}</p>
                </div>
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={retryingId === e.assignmentId}
                  onClick={() => retry(e.assignmentId)}
                >
                  {retryingId === e.assignmentId ? dict.common.loading : dict.wpx.retry}
                </Button>
              </div>
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
