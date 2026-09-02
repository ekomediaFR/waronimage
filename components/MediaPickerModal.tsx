"use client";

// Full-screen modal grid to hand-pick a different media item for a page.

import { useMemo, useState } from "react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { useI18n } from "@/components/LanguageProvider";
import type { WpMediaLiteDto } from "@/lib/types";

export function MediaPickerModal({
  media,
  currentMediaId,
  onSelect,
  onClose,
}: {
  media: WpMediaLiteDto[];
  currentMediaId: string;
  onSelect: (mediaId: string) => void;
  onClose: () => void;
}) {
  const { dict } = useI18n();
  const [query, setQuery] = useState("");

  const filtered = useMemo(() => {
    const q = query.toLowerCase();
    return q ? media.filter((m) => m.filename.toLowerCase().includes(q)) : media;
  }, [media, query]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" onClick={onClose}>
      <div
        className="flex max-h-[85vh] w-full max-w-4xl flex-col rounded-lg border border-zinc-700 bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center gap-3 border-b border-zinc-800 p-4">
          <h3 className="text-sm font-semibold text-zinc-100">{dict.review.swapTitle}</h3>
          <Input
            autoFocus
            placeholder={dict.review.swapSearch}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            className="h-8 max-w-xs"
          />
          <Button variant="ghost" size="sm" className="ml-auto" onClick={onClose}>
            {dict.common.close}
          </Button>
        </div>
        <div className="grid grid-cols-3 gap-2 overflow-y-auto p-4 sm:grid-cols-4 md:grid-cols-5">
          {filtered.map((m) => (
            <button
              key={m.id}
              onClick={() => onSelect(m.id)}
              className={`group relative overflow-hidden rounded-md border text-left transition-colors ${
                m.id === currentMediaId
                  ? "border-amber-500 ring-2 ring-amber-500/50"
                  : "border-zinc-800 hover:border-amber-600/70"
              }`}
            >
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
              {!m.isUsable && (
                <Badge variant="red" className="absolute right-1 top-1">
                  {dict.syncTab.unusable}
                </Badge>
              )}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
