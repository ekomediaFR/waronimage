"use client";

import { useCallback, useEffect, useState } from "react";
import { useAppStore } from "@/lib/store";
import { NICHES, CPTS, type PageDto } from "@/lib/types";
import { Input, Select } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { MatchScoreBadge } from "./MatchScoreBadge";
import { ImageCard } from "./ImageCard";
import { ImageMetaPanel } from "./ImageMetaPanel";

/** Page browser: filterable table + right-hand detail panel. */
export function PageBrowser({ siteId }: { siteId: string }) {
  const { selectedPageId, selectPage, pageFilters, setPageFilter } = useAppStore();
  const [pages, setPages] = useState<PageDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const params = new URLSearchParams();
    if (pageFilters.niche) params.set("niche", pageFilters.niche);
    if (pageFilters.cpt) params.set("cpt", pageFilters.cpt);
    if (pageFilters.coverage) params.set("coverage", pageFilters.coverage);
    if (pageFilters.q) params.set("q", pageFilters.q);
    const res = await fetch(`/api/sites/${siteId}/pages?${params}`);
    if (res.ok) setPages((await res.json()).pages);
    setLoading(false);
  }, [siteId, pageFilters]);

  useEffect(() => {
    load();
  }, [load]);

  const selected = pages.find((p) => p.id === selectedPageId) || null;

  async function generateFor(pageId: string) {
    setBusy(true);
    await fetch("/api/generate", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ pageIds: [pageId] }),
    });
    setBusy(false);
    load();
  }

  async function exportPage(pageId: string) {
    const page = pages.find((p) => p.id === pageId);
    if (!page) return;
    setBusy(true);
    await fetch("/api/export/wordpress", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        siteId,
        aiImageIds: page.aiImages.filter((i) => i.status === "approved").map((i) => i.id),
        assignmentIds: page.stockAssignments.filter((a) => a.approved).map((a) => a.id),
      }),
    });
    setBusy(false);
    load();
  }

  return (
    <div className="flex gap-4">
      <div className={selected ? "w-1/2 xl:w-3/5" : "w-full"}>
        <div className="mb-3 flex flex-wrap gap-2">
          <Input
            placeholder="Search title / URL…"
            className="w-52"
            value={pageFilters.q}
            onChange={(e) => setPageFilter("q", e.target.value)}
          />
          <Select value={pageFilters.niche} onChange={(e) => setPageFilter("niche", e.target.value)}>
            <option value="">All niches</option>
            {NICHES.map((n) => (
              <option key={n} value={n}>{n}</option>
            ))}
          </Select>
          <Select value={pageFilters.cpt} onChange={(e) => setPageFilter("cpt", e.target.value)}>
            <option value="">All CPTs</option>
            {CPTS.map((c) => (
              <option key={c} value={c}>{c}</option>
            ))}
          </Select>
          <Select value={pageFilters.coverage} onChange={(e) => setPageFilter("coverage", e.target.value)}>
            <option value="">Any coverage</option>
            <option value="none">No images</option>
            <option value="ai">Has AI images</option>
            <option value="stock">Has stock images</option>
            <option value="full">Covered</option>
          </Select>
        </div>

        <div className="overflow-x-auto rounded-lg border border-zinc-800">
          <table className="w-full text-sm">
            <thead className="bg-zinc-900 text-left text-xs uppercase tracking-wider text-zinc-500">
              <tr>
                <th className="p-2.5">Page</th>
                <th className="p-2.5">CPT</th>
                <th className="p-2.5">Niche</th>
                <th className="p-2.5">City</th>
                <th className="p-2.5 text-center">AI</th>
                <th className="p-2.5 text-center">Stock</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={6} className="p-6 text-center text-zinc-500">Loading pages…</td></tr>
              ) : pages.length === 0 ? (
                <tr><td colSpan={6} className="p-6 text-center text-zinc-500">No pages yet — run a sitemap ingest.</td></tr>
              ) : (
                pages.map((p) => (
                  <tr
                    key={p.id}
                    onClick={() => selectPage(p.id === selectedPageId ? null : p.id)}
                    className={`cursor-pointer border-t border-zinc-800/70 hover:bg-zinc-900 ${p.id === selectedPageId ? "bg-amber-500/5" : ""}`}
                  >
                    <td className="max-w-64 p-2.5">
                      <p className="truncate font-medium text-zinc-200">{p.title}</p>
                      <p className="truncate text-xs text-zinc-500">{p.url}</p>
                    </td>
                    <td className="p-2.5"><Badge>{p.cpt}</Badge></td>
                    <td className="p-2.5 text-zinc-400">{p.niche}</td>
                    <td className="p-2.5 text-zinc-400">{p.city || "—"}</td>
                    <td className="p-2.5 text-center font-mono text-zinc-300">{p.aiImages.length}</td>
                    <td className="p-2.5 text-center font-mono text-zinc-300">{p.stockAssignments.length}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {selected && (
        <aside className="w-1/2 space-y-4 rounded-lg border border-zinc-800 bg-zinc-900/40 p-4 xl:w-2/5">
          <div>
            <div className="flex items-start justify-between gap-2">
              <h2 className="font-semibold text-zinc-100">{selected.title}</h2>
              <Button size="sm" variant="ghost" onClick={() => selectPage(null)}>✕</Button>
            </div>
            <a href={selected.url} target="_blank" rel="noreferrer" className="break-all text-xs text-sky-400 hover:underline">
              {selected.url}
            </a>
            <div className="mt-2 flex flex-wrap gap-1.5">
              <Badge variant="amber">{selected.pageIntent}</Badge>
              <Badge>{selected.niche}</Badge>
              <Badge>{selected.cpt}</Badge>
              {selected.city && <Badge>{selected.city}</Badge>}
            </div>
            {selected.contentSummary && <p className="mt-2 text-xs text-zinc-400">{selected.contentSummary}</p>}
          </div>

          <div className="flex gap-2">
            <Button size="sm" disabled={busy} onClick={() => generateFor(selected.id)}>
              Generate AI images
            </Button>
            <Button size="sm" variant="secondary" disabled={busy} onClick={() => exportPage(selected.id)}>
              Export to WordPress
            </Button>
          </div>

          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">
              AI images ({selected.aiImages.length})
            </h3>
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
              {selected.aiImages.map((img) => (
                <ImageCard key={img.id} image={img} onChanged={load} />
              ))}
              {!selected.aiImages.length && <p className="text-xs text-zinc-600">No AI images yet.</p>}
            </div>
          </section>

          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">
              Stock assignments ({selected.stockAssignments.length})
            </h3>
            <div className="space-y-3">
              {selected.stockAssignments.map((a) => (
                <StockAssignmentRow key={a.id} assignment={a} onChanged={load} />
              ))}
              {!selected.stockAssignments.length && <p className="text-xs text-zinc-600">No stock images assigned.</p>}
            </div>
          </section>
        </aside>
      )}
    </div>
  );
}

function StockAssignmentRow({
  assignment,
  onChanged,
}: {
  assignment: PageDto["stockAssignments"][number];
  onChanged: () => void;
}) {
  const [editing, setEditing] = useState(false);
  async function approve(approved: boolean) {
    await fetch(`/api/assignments/${assignment.id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ approved }),
    });
    onChanged();
  }
  return (
    <div className="flex gap-3 rounded-md border border-zinc-800 bg-zinc-900 p-2">
      {assignment.stockImage && (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={assignment.stockImage.originalUrl}
          alt={assignment.altText}
          className="h-16 w-24 flex-none rounded object-cover"
        />
      )}
      <div className="min-w-0 flex-1 space-y-1">
        <div className="flex flex-wrap items-center gap-1.5">
          <MatchScoreBadge score={assignment.matchScore} />
          <Badge>{assignment.position}</Badge>
          <Badge>{assignment.assignedBy}</Badge>
          {assignment.approved && <Badge variant="green">approved</Badge>}
          {assignment.exportStatus === "exported" && <Badge variant="blue">in WP</Badge>}
        </div>
        <p className="truncate text-xs text-zinc-400" title={assignment.altText}>{assignment.altText}</p>
        <div className="flex gap-1.5">
          <Button size="sm" variant="success" onClick={() => approve(true)} disabled={assignment.approved}>
            Approve
          </Button>
          <Button size="sm" variant="destructive" onClick={() => approve(false)} disabled={!assignment.approved}>
            Revoke
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setEditing((e) => !e)}>
            {editing ? "Close" : "Edit"}
          </Button>
        </div>
        {editing && (
          <ImageMetaPanel
            endpoint={`/api/assignments/${assignment.id}`}
            initial={{ altText: assignment.altText, filename: assignment.filename }}
            onSaved={() => {
              setEditing(false);
              onChanged();
            }}
          />
        )}
      </div>
    </div>
  );
}
