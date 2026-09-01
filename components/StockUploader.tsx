"use client";

import { useCallback, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

interface Props {
  siteId: string;
  onUploaded?: () => void;
}

/** Drag-and-drop bulk uploader for stock company photos. */
export function StockUploader({ siteId, onUploaded }: Props) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragOver, setDragOver] = useState(false);
  const [progress, setProgress] = useState<string | null>(null);

  const upload = useCallback(
    async (files: FileList | File[]) => {
      const list = Array.from(files).filter((f) => f.type.startsWith("image/"));
      if (!list.length) return;
      const BATCH = 10;
      let done = 0;
      for (let i = 0; i < list.length; i += BATCH) {
        const fd = new FormData();
        fd.set("siteId", siteId);
        for (const f of list.slice(i, i + BATCH)) fd.append("files", f);
        setProgress(`Uploading ${Math.min(i + BATCH, list.length)}/${list.length}…`);
        const res = await fetch("/api/stock/upload", { method: "POST", body: fd });
        if (res.ok) done += (await res.json()).uploaded;
      }
      setProgress(`Uploaded ${done}/${list.length} photos.`);
      onUploaded?.();
    },
    [siteId, onUploaded]
  );

  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-8 text-center transition-colors",
        dragOver ? "border-amber-500 bg-amber-500/5" : "border-zinc-700 bg-zinc-900/40"
      )}
      onDragOver={(e) => {
        e.preventDefault();
        setDragOver(true);
      }}
      onDragLeave={() => setDragOver(false)}
      onDrop={(e) => {
        e.preventDefault();
        setDragOver(false);
        upload(e.dataTransfer.files);
      }}
    >
      <p className="text-sm text-zinc-300">Drop company photos here (100+ at once is fine)</p>
      <p className="text-xs text-zinc-500">JPEG / PNG / WebP — converted to WebP on upload</p>
      <Button variant="secondary" size="sm" type="button" onClick={() => inputRef.current?.click()}>
        Browse files
      </Button>
      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        multiple
        hidden
        onChange={(e) => e.target.files && upload(e.target.files)}
      />
      {progress && <p className="text-xs text-amber-400">{progress}</p>}
    </div>
  );
}
