"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input, Label, Textarea } from "@/components/ui/input";
import { useI18n } from "./LanguageProvider";

interface Props {
  endpoint: string; // PATCH target
  initial: { altText: string; title?: string; filename: string; caption?: string };
  onSaved?: () => void;
}

/** Inline ALT / title / filename / caption editor used for both AI and stock images. */
export function ImageMetaPanel({ endpoint, initial, onSaved }: Props) {
  const { dict } = useI18n();
  const [form, setForm] = useState(initial);
  const [busy, setBusy] = useState(false);

  async function save() {
    setBusy(true);
    await fetch(endpoint, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(form),
    });
    setBusy(false);
    onSaved?.();
  }

  return (
    <div className="space-y-2 rounded-md border border-zinc-800 bg-zinc-950 p-3">
      <div>
        <Label>{dict.meta.alt} ({form.altText.length}/125)</Label>
        <Textarea
          value={form.altText}
          maxLength={125}
          onChange={(e) => setForm({ ...form, altText: e.target.value })}
        />
      </div>
      {form.title !== undefined && (
        <div>
          <Label>{dict.meta.title} ({(form.title || "").length}/60)</Label>
          <Input value={form.title} maxLength={60} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        </div>
      )}
      <div>
        <Label>{dict.meta.filename}</Label>
        <Input value={form.filename} onChange={(e) => setForm({ ...form, filename: e.target.value })} />
      </div>
      {form.caption !== undefined && (
        <div>
          <Label>{dict.meta.caption} ({(form.caption || "").length}/160)</Label>
          <Input value={form.caption} maxLength={160} onChange={(e) => setForm({ ...form, caption: e.target.value })} />
        </div>
      )}
      <Button size="sm" disabled={busy} onClick={save}>
        {busy ? dict.common.saving : dict.meta.save}
      </Button>
    </div>
  );
}
