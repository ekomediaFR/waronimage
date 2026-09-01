"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { useI18n } from "./LanguageProvider";
import { fmt } from "@/lib/i18n";

/** Re-runs sitemap ingestion for a site (picks up new URLs). */
export function ReingestButton({ siteId }: { siteId: string }) {
  const { dict } = useI18n();
  const [state, setState] = useState<"idle" | "running" | "done" | "error">("idle");
  const [message, setMessage] = useState("");

  async function run() {
    setState("running");
    setMessage("");
    try {
      const res = await fetch(`/api/sites/${siteId}/ingest`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ maxPages: 300 }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
      setState("done");
      setMessage(fmt(dict.pagesView.reingestResult, { added: data.processed, existing: data.skippedExisting, failed: data.failed }));
      setTimeout(() => window.location.reload(), 1200);
    } catch (err) {
      setState("error");
      setMessage((err as Error).message);
    }
  }

  return (
    <div className="flex items-center gap-2">
      {message && (
        <span className={`text-xs ${state === "error" ? "text-red-400" : "text-zinc-400"}`}>{message}</span>
      )}
      <Button size="sm" variant="secondary" disabled={state === "running"} onClick={run}>
        {state === "running" ? dict.pagesView.reingesting : dict.pagesView.reingest}
      </Button>
    </div>
  );
}
