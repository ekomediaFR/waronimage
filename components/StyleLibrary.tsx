"use client";

import { useEffect, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input, Label, Textarea } from "@/components/ui/input";
import type { ImageStyleDto } from "@/lib/types";

interface Props {
  selectedStyleId?: string | null;
  onSelect?: (styleId: string | null) => void;
}

/** Image style library: list, select for generation, create new styles. */
export function StyleLibrary({ selectedStyleId, onSelect }: Props) {
  const [styles, setStyles] = useState<ImageStyleDto[]>([]);
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState({ name: "", description: "", promptTemplate: "", niches: "" });

  async function load() {
    const res = await fetch("/api/styles");
    if (res.ok) setStyles((await res.json()).styles);
  }
  useEffect(() => {
    load();
  }, []);

  async function create(e: React.FormEvent) {
    e.preventDefault();
    await fetch("/api/styles", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        name: form.name,
        description: form.description,
        promptTemplate: form.promptTemplate,
        niches: form.niches.split(",").map((n) => n.trim()).filter(Boolean),
      }),
    });
    setForm({ name: "", description: "", promptTemplate: "", niches: "" });
    setCreating(false);
    load();
  }

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <CardTitle>Style library</CardTitle>
        <Button size="sm" variant="secondary" onClick={() => setCreating((c) => !c)}>
          {creating ? "Cancel" : "+ New style"}
        </Button>
      </CardHeader>
      <CardContent className="space-y-2">
        {onSelect && (
          <button
            onClick={() => onSelect(null)}
            className={`w-full rounded-md border p-2 text-left text-sm ${!selectedStyleId ? "border-amber-500 bg-amber-500/10" : "border-zinc-800 hover:border-zinc-600"}`}
          >
            <span className="font-medium text-zinc-200">Auto-match styles</span>
            <p className="text-xs text-zinc-500">Best 2 styles per page (niche + intent)</p>
          </button>
        )}
        {styles.map((style) => (
          <button
            key={style.id}
            onClick={() => onSelect?.(style.id)}
            className={`w-full rounded-md border p-2 text-left text-sm ${selectedStyleId === style.id ? "border-amber-500 bg-amber-500/10" : "border-zinc-800 hover:border-zinc-600"} ${onSelect ? "" : "cursor-default"}`}
          >
            <div className="flex items-center justify-between">
              <span className="font-medium text-zinc-200">{style.name}</span>
              <span className="text-[10px] text-zinc-500">{style.formats?.ratio}</span>
            </div>
            <p className="line-clamp-1 text-xs text-zinc-500">{style.description}</p>
            <div className="mt-1 flex flex-wrap gap-1">
              {style.niches.map((n) => (
                <Badge key={n}>{n}</Badge>
              ))}
            </div>
          </button>
        ))}
        {creating && (
          <form onSubmit={create} className="space-y-2 rounded-md border border-zinc-800 bg-zinc-950 p-3">
            <div>
              <Label>Name</Label>
              <Input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </div>
            <div>
              <Label>Description</Label>
              <Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <div>
              <Label>Prompt template ({"{niche} {city} {service} {primary_color}"})</Label>
              <Textarea
                required
                value={form.promptTemplate}
                onChange={(e) => setForm({ ...form, promptTemplate: e.target.value })}
              />
            </div>
            <div>
              <Label>Niches (comma-separated)</Label>
              <Input value={form.niches} onChange={(e) => setForm({ ...form, niches: e.target.value })} placeholder="moving, storage" />
            </div>
            <Button size="sm" type="submit">
              Create style
            </Button>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
