"use client";

import { useState } from "react";
import { Button, type ButtonProps } from "@/components/ui/button";

interface SyncButtonProps extends Pick<ButtonProps, "variant" | "size" | "className"> {
  endpoint: string;
  method?: "POST" | "PATCH" | "DELETE";
  body?: unknown;
  label: string;
  loadingLabel: string;
  disabled?: boolean;
  onDone?: (json: Record<string, unknown>, ok: boolean) => void;
}

/** Action button that calls an API endpoint with a spinner and hands back the JSON. */
export function SyncButton({
  endpoint,
  method = "POST",
  body,
  label,
  loadingLabel,
  disabled,
  onDone,
  ...buttonProps
}: SyncButtonProps) {
  const [loading, setLoading] = useState(false);

  async function run() {
    setLoading(true);
    try {
      const res = await fetch(endpoint, {
        method,
        headers: { "Content-Type": "application/json" },
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
      });
      const json = (await res.json().catch(() => ({}))) as Record<string, unknown>;
      onDone?.(json, res.ok);
    } catch (err) {
      onDone?.({ error: (err as Error).message }, false);
    } finally {
      setLoading(false);
    }
  }

  return (
    <Button onClick={run} disabled={disabled || loading} {...buttonProps}>
      {loading && (
        <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
      )}
      {loading ? loadingLabel : label}
    </Button>
  );
}
