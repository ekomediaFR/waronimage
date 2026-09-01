"use client";

import { useEffect, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Select } from "@/components/ui/input";
import { MatchScoreBadge } from "@/components/MatchScoreBadge";
import { pct } from "@/lib/utils";
import type { SiteDto } from "@/lib/types";
import { useI18n } from "@/components/LanguageProvider";
import { fmt } from "@/lib/i18n";

interface Metrics {
  totalPages: number;
  withAi: number;
  withStock: number;
  withNone: number;
  noImagePages: { id: string; title: string; url: string }[];
  avgScore: number;
  byNiche: Record<string, { total: number; covered: number }>;
  byCpt: Record<string, { total: number; covered: number }>;
  worstPages: { id: string; title: string; url: string; niche: string; bestScore: number }[];
  pendingApproval: number;
  qualityHistogram: { quality: number | null; count: number }[];
}

export default function DashboardPage() {
  const { dict } = useI18n();
  const [sites, setSites] = useState<SiteDto[]>([]);
  const [siteId, setSiteId] = useState("");
  const [metrics, setMetrics] = useState<Metrics | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    fetch("/api/sites")
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error("db"))))
      .then((d) => setSites(d.sites))
      .catch(() => setError(true));
  }, []);

  useEffect(() => {
    const params = siteId ? `?siteId=${siteId}` : "";
    fetch(`/api/dashboard${params}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error("db"))))
      .then(setMetrics)
      .catch(() => setError(true));
  }, [siteId]);

  if (error) {
    return (
      <Card className="border-amber-800 bg-amber-950/20">
        <CardContent className="p-6 text-sm text-amber-300">
          {dict.dash.dbDown}
          {dict.dash.dbHint}
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight">{dict.dash.title}</h1>
        <Select value={siteId} onChange={(e) => setSiteId(e.target.value)}>
          <option value="">{dict.dash.allSites}</option>
          {sites.map((s) => (
            <option key={s.id} value={s.id}>{s.name}</option>
          ))}
        </Select>
      </div>

      {!metrics ? (
        <p className="text-sm text-zinc-500">{dict.dash.loadingMetrics}</p>
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <Stat label={dict.dash.pagesTotal} value={metrics.totalPages} />
            <Stat label={dict.dash.withAi} value={`${metrics.withAi} (${pct(metrics.withAi, metrics.totalPages)})`} />
            <Stat label={dict.dash.withStock} value={`${metrics.withStock} (${pct(metrics.withStock, metrics.totalPages)})`} />
            <Stat label={dict.dash.noImages} value={metrics.withNone} tone="text-red-400" />
            <Stat label={dict.dash.pending} value={metrics.pendingApproval} tone="text-amber-400" />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <BarCard title={dict.dash.byNiche} coveredTemplate={dict.dash.covered} data={metrics.byNiche} noData={dict.dash.noData} />
            <BarCard title={dict.dash.byCpt} coveredTemplate={dict.dash.covered} data={metrics.byCpt} noData={dict.dash.noData} />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>{dict.dash.weakest}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-1.5">
                {metrics.worstPages.map((p) => (
                  <div key={p.id} className="flex items-center justify-between gap-3 text-sm">
                    <span className="min-w-0 flex-1 truncate text-zinc-300">{p.title}</span>
                    <MatchScoreBadge score={p.bestScore} />
                  </div>
                ))}
                {!metrics.worstPages.length && <p className="text-xs text-zinc-600">{dict.dash.noPages}</p>}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>
                  {dict.dash.avgScore}{" "}
                  <span className="text-zinc-100">{(metrics.avgScore * 100).toFixed(1)}%</span>
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="mb-2 text-xs uppercase tracking-wider text-zinc-500">{dict.dash.quality}</p>
                <div className="flex h-24 items-end gap-1">
                  {Array.from({ length: 10 }, (_, i) => i + 1).map((q) => {
                    const row = metrics.qualityHistogram.find((r) => r.quality === q);
                    const max = Math.max(1, ...metrics.qualityHistogram.map((r) => r.count));
                    const h = row ? Math.max(6, (row.count / max) * 90) : 2;
                    return (
                      <div key={q} className="flex flex-1 flex-col items-center gap-1">
                        <div className="w-full rounded-t bg-amber-500/70" style={{ height: `${h}px` }} title={`${row?.count ?? 0}`} />
                        <span className="text-[10px] text-zinc-600">{q}</span>
                      </div>
                    );
                  })}
                </div>
              </CardContent>
            </Card>
          </div>

          {metrics.noImagePages.length > 0 && (
            <Card className="border-red-900/60">
              <CardHeader>
                <CardTitle className="text-red-400">{dict.dash.alert}</CardTitle>
              </CardHeader>
              <CardContent className="grid gap-1 sm:grid-cols-2">
                {metrics.noImagePages.map((p) => (
                  <a key={p.id} href={p.url} target="_blank" rel="noreferrer" className="truncate text-xs text-zinc-400 hover:text-zinc-200">
                    {p.title} — {p.url}
                  </a>
                ))}
              </CardContent>
            </Card>
          )}
        </>
      )}
    </div>
  );
}

function Stat({ label, value, tone }: { label: string; value: number | string; tone?: string }) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-xs uppercase tracking-wider text-zinc-500">{label}</p>
        <p className={`mt-1 text-2xl font-bold ${tone || "text-zinc-100"}`}>{value}</p>
      </CardContent>
    </Card>
  );
}

function BarCard({
  title,
  data,
  coveredTemplate,
  noData,
}: {
  title: string;
  data: Record<string, { total: number; covered: number }>;
  coveredTemplate: string;
  noData: string;
}) {
  const entries = Object.entries(data).sort((a, b) => b[1].total - a[1].total);
  const max = Math.max(1, ...entries.map(([, v]) => v.total));
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-2">
        {entries.map(([key, v]) => (
          <div key={key}>
            <div className="mb-0.5 flex justify-between text-xs">
              <span className="text-zinc-300">{key}</span>
              <span className="text-zinc-500">{fmt(coveredTemplate, { c: v.covered, t: v.total })}</span>
            </div>
            <div className="h-2.5 w-full overflow-hidden rounded bg-zinc-800" style={{ width: `${(v.total / max) * 100}%`, minWidth: "10%" }}>
              <div className="h-full rounded bg-emerald-600" style={{ width: pct(v.covered, v.total) }} />
            </div>
          </div>
        ))}
        {!entries.length && <p className="text-xs text-zinc-600">{noData}</p>}
      </CardContent>
    </Card>
  );
}
