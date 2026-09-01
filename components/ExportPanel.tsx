"use client";

import { useEffect, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";

interface ExportResult {
  exported: number;
  failed: number;
  results: { type: string; id: string; ok: boolean; mediaId?: number; error?: string }[];
}

interface Counts {
  approvedAi: number;
  approvedStock: number;
  exportedAi: number;
  exportedStock: number;
}

/** WordPress export panel: pushes all approved, not-yet-exported images. */
export function ExportPanel({ siteId, hasWpConfig }: { siteId: string; hasWpConfig: boolean }) {
  const [counts, setCounts] = useState<Counts | null>(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<ExportResult | null>(null);

  async function load() {
    const res = await fetch(`/api/sites/${siteId}/pages`);
    if (!res.ok) return;
    const { pages } = await res.json();
    const c: Counts = { approvedAi: 0, approvedStock: 0, exportedAi: 0, exportedStock: 0 };
    for (const p of pages) {
      for (const i of p.aiImages) {
        if (i.exportStatus === "exported") c.exportedAi++;
        else if (i.status === "approved") c.approvedAi++;
      }
      for (const a of p.stockAssignments) {
        if (a.exportStatus === "exported") c.exportedStock++;
        else if (a.approved) c.approvedStock++;
      }
    }
    setCounts(c);
  }
  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [siteId]);

  async function runExport() {
    setBusy(true);
    setResult(null);
    const res = await fetch("/api/export/wordpress", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ siteId }),
    });
    setResult(await res.json());
    setBusy(false);
    load();
  }

  return (
    <div className="max-w-2xl space-y-4">
      {!hasWpConfig && (
        <Card className="border-amber-800 bg-amber-950/30">
          <CardContent className="p-4 text-sm text-amber-300">
            This site has no WordPress credentials. Add <code>wpApiUrl</code> and <code>wpAuthToken</code> on the site
            settings to enable export.
          </CardContent>
        </Card>
      )}
      <Card>
        <CardHeader>
          <CardTitle>Ready for export</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          {counts ? (
            <ul className="space-y-1.5 text-sm text-zinc-300">
              <li className="flex justify-between"><span>Approved AI images</span><span className="font-mono">{counts.approvedAi}</span></li>
              <li className="flex justify-between"><span>Approved stock assignments</span><span className="font-mono">{counts.approvedStock}</span></li>
              <li className="flex justify-between text-zinc-500"><span>Already in WordPress</span><span className="font-mono">{counts.exportedAi + counts.exportedStock}</span></li>
            </ul>
          ) : (
            <p className="text-sm text-zinc-500">Loading…</p>
          )}
          <Button onClick={runExport} disabled={busy || !hasWpConfig || !counts || counts.approvedAi + counts.approvedStock === 0}>
            {busy ? "Exporting…" : "Export approved images to WordPress"}
          </Button>
        </CardContent>
      </Card>

      {result && (
        <Card>
          <CardHeader>
            <CardTitle>Export result</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            <div className="flex gap-2">
              <Badge variant="green">{result.exported} exported</Badge>
              {result.failed > 0 && <Badge variant="red">{result.failed} failed</Badge>}
            </div>
            <ul className="max-h-64 space-y-1 overflow-y-auto text-xs">
              {result.results?.map((r) => (
                <li key={r.id} className={r.ok ? "text-emerald-400" : "text-red-400"}>
                  [{r.type}] {r.id} — {r.ok ? `media #${r.mediaId}` : r.error}
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
