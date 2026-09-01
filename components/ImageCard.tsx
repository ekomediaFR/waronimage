"use client";

import { useState } from "react";
import { MatchScoreBadge } from "./MatchScoreBadge";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type { AiImageDto } from "@/lib/types";
import { ImageMetaPanel } from "./ImageMetaPanel";

interface Props {
  image: AiImageDto;
  onChanged?: () => void;
}

/** AI image variant card with approve / reject / metadata editing. */
export function ImageCard({ image, onChanged }: Props) {
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(false);

  async function setStatus(status: "approved" | "rejected") {
    setBusy(true);
    await fetch(`/api/ai-images/${image.id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ status }),
    });
    setBusy(false);
    onChanged?.();
  }

  return (
    <div className="overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900">
      {image.imageUrl ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={image.imageUrl} alt={image.altText} className="aspect-video w-full object-cover" />
      ) : (
        <div className="flex aspect-video w-full items-center justify-center bg-zinc-950 text-xs text-zinc-600">
          {image.status === "pending" ? "generating…" : "no image"}
        </div>
      )}
      <div className="space-y-2 p-3">
        <div className="flex flex-wrap items-center gap-1.5">
          <MatchScoreBadge score={image.matchScore} />
          <Badge>v{image.variantIndex}</Badge>
          <Badge
            variant={image.status === "approved" ? "green" : image.status === "rejected" ? "red" : "default"}
          >
            {image.status}
          </Badge>
          {image.exportStatus === "exported" && <Badge variant="blue">in WP</Badge>}
        </div>
        <p className="truncate font-mono text-[11px] text-zinc-500" title={image.filename}>
          {image.filename}
        </p>
        <p className="line-clamp-2 text-xs text-zinc-400" title={image.altText}>
          {image.altText}
        </p>
        <div className="flex gap-1.5">
          <Button size="sm" variant="success" disabled={busy || image.status === "approved"} onClick={() => setStatus("approved")}>
            Approve
          </Button>
          <Button size="sm" variant="destructive" disabled={busy || image.status === "rejected"} onClick={() => setStatus("rejected")}>
            Reject
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setEditing((e) => !e)}>
            {editing ? "Close" : "Edit"}
          </Button>
        </div>
        {editing && (
          <ImageMetaPanel
            endpoint={`/api/ai-images/${image.id}`}
            initial={{ altText: image.altText, title: image.title, filename: image.filename, caption: image.caption ?? "" }}
            onSaved={() => {
              setEditing(false);
              onChanged?.();
            }}
          />
        )}
      </div>
    </div>
  );
}
