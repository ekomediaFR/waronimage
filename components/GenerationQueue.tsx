"use client";

import { useEffect, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useI18n } from "./LanguageProvider";

interface Status {
  pending: number;
  generated: number;
  approved: number;
  rejected: number;
  queue: { waiting?: number; active?: number; completed?: number; failed?: number } | null;
}

/** Live generation status monitor — polls /api/generate/status every 3s. */
export function GenerationQueue({ siteId, active }: { siteId: string; active: boolean }) {
  const { dict } = useI18n();
  const [status, setStatus] = useState<Status | null>(null);

  useEffect(() => {
    let stop = false;
    async function poll() {
      try {
        const res = await fetch(`/api/generate/status?siteId=${siteId}`);
        if (res.ok && !stop) setStatus(await res.json());
      } catch {
        /* transient */
      }
    }
    poll();
    const interval = setInterval(poll, active ? 3000 : 15000);
    return () => {
      stop = true;
      clearInterval(interval);
    };
  }, [siteId, active]);

  const rows: { label: string; value: number | string; tone: string }[] = status
    ? [
        { label: dict.queue.queuedProcessing, value: status.queue ? `${status.queue.waiting ?? 0} / ${status.queue.active ?? 0}` : status.pending, tone: "text-amber-400" },
        { label: dict.queue.generated, value: status.generated, tone: "text-sky-400" },
        { label: dict.queue.approved, value: status.approved, tone: "text-emerald-400" },
        { label: dict.queue.failed, value: status.rejected, tone: "text-red-400" },
      ]
    : [];

  return (
    <Card>
      <CardHeader>
        <CardTitle>{dict.queue.title}</CardTitle>
      </CardHeader>
      <CardContent>
        {!status ? (
          <p className="text-sm text-zinc-500">{dict.common.loading}</p>
        ) : (
          <ul className="space-y-2">
            {rows.map((r) => (
              <li key={r.label} className="flex items-center justify-between text-sm">
                <span className="text-zinc-400">{r.label}</span>
                <span className={`font-mono font-semibold ${r.tone}`}>{r.value}</span>
              </li>
            ))}
          </ul>
        )}
        {status && !status.queue && (
          <p className="mt-3 text-[11px] text-zinc-600">{dict.queue.redisNote}</p>
        )}
      </CardContent>
    </Card>
  );
}
