"use client";

// SEO metadata editor with live character counters (125/60/160/300 + filename).

import { useEffect, useState } from "react";
import { Input, Label, Textarea } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { useI18n } from "@/components/LanguageProvider";
import { cn } from "@/lib/utils";

export interface SeoFields {
  seoAltText: string;
  seoTitle: string;
  seoCaption: string;
  seoDescription: string;
  seoFilename: string;
}

const LIMITS: Record<keyof SeoFields, number> = {
  seoAltText: 125,
  seoTitle: 60,
  seoCaption: 160,
  seoDescription: 300,
  seoFilename: 70,
};

function Counter({ value, max }: { value: string; max: number }) {
  return (
    <span className={cn("ml-auto text-[10px] tabular-nums", value.length > max ? "text-red-400" : "text-zinc-500")}>
      {value.length}/{max}
    </span>
  );
}

export function MetadataEditor({
  initial,
  onSave,
  saving,
}: {
  initial: Partial<SeoFields>;
  onSave: (fields: SeoFields) => void;
  saving: boolean;
}) {
  const { dict } = useI18n();
  const [fields, setFields] = useState<SeoFields>({
    seoAltText: initial.seoAltText || "",
    seoTitle: initial.seoTitle || "",
    seoCaption: initial.seoCaption || "",
    seoDescription: initial.seoDescription || "",
    seoFilename: initial.seoFilename || "",
  });

  // Re-seed when the assignment (or its media) changes under us.
  useEffect(() => {
    setFields({
      seoAltText: initial.seoAltText || "",
      seoTitle: initial.seoTitle || "",
      seoCaption: initial.seoCaption || "",
      seoDescription: initial.seoDescription || "",
      seoFilename: initial.seoFilename || "",
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initial.seoAltText, initial.seoTitle, initial.seoCaption, initial.seoDescription, initial.seoFilename]);

  const set = (key: keyof SeoFields) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
    setFields((f) => ({ ...f, [key]: e.target.value }));

  return (
    <div className="grid gap-2.5 sm:grid-cols-2">
      <div>
        <Label className="flex items-center">{dict.meta.alt} <Counter value={fields.seoAltText} max={LIMITS.seoAltText} /></Label>
        <Input value={fields.seoAltText} onChange={set("seoAltText")} />
      </div>
      <div>
        <Label className="flex items-center">{dict.meta.title} <Counter value={fields.seoTitle} max={LIMITS.seoTitle} /></Label>
        <Input value={fields.seoTitle} onChange={set("seoTitle")} />
      </div>
      <div>
        <Label className="flex items-center">{dict.meta.caption} <Counter value={fields.seoCaption} max={LIMITS.seoCaption} /></Label>
        <Input value={fields.seoCaption} onChange={set("seoCaption")} />
      </div>
      <div>
        <Label className="flex items-center">{dict.meta.filename} <Counter value={fields.seoFilename} max={LIMITS.seoFilename} /></Label>
        <Input value={fields.seoFilename} onChange={set("seoFilename")} className="font-mono text-xs" />
      </div>
      <div className="sm:col-span-2">
        <Label className="flex items-center">{dict.meta.description} <Counter value={fields.seoDescription} max={LIMITS.seoDescription} /></Label>
        <Textarea value={fields.seoDescription} onChange={set("seoDescription")} rows={2} />
      </div>
      <div className="sm:col-span-2">
        <Button size="sm" variant="secondary" disabled={saving} onClick={() => onSave(fields)}>
          {saving ? dict.common.saving : dict.meta.save}
        </Button>
      </div>
    </div>
  );
}
