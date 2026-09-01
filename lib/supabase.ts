import { createClient, SupabaseClient } from "@supabase/supabase-js";
import { promises as fs } from "fs";
import path from "path";

let client: SupabaseClient | null = null;

export function hasSupabase(): boolean {
  return Boolean(process.env.NEXT_PUBLIC_SUPABASE_URL && process.env.SUPABASE_SERVICE_ROLE_KEY);
}

export function getSupabase(): SupabaseClient {
  if (!hasSupabase()) {
    throw new Error("Supabase is not configured (NEXT_PUBLIC_SUPABASE_URL / SUPABASE_SERVICE_ROLE_KEY).");
  }
  if (!client) {
    client = createClient(process.env.NEXT_PUBLIC_SUPABASE_URL!, process.env.SUPABASE_SERVICE_ROLE_KEY!, {
      auth: { persistSession: false },
    });
  }
  return client;
}

const BUCKET = process.env.SUPABASE_BUCKET || "waronimage";

/**
 * Store an image buffer and return a public URL.
 * Uses Supabase Storage when configured, otherwise falls back to /public/uploads
 * (local dev only — ephemeral on serverless hosts).
 */
export async function storeImage(
  buffer: Buffer,
  storagePath: string,
  contentType = "image/webp"
): Promise<string> {
  if (hasSupabase()) {
    const supabase = getSupabase();
    const { error } = await supabase.storage
      .from(BUCKET)
      .upload(storagePath, buffer, { contentType, upsert: true });
    if (error) throw new Error(`Supabase upload failed: ${error.message}`);
    const { data } = supabase.storage.from(BUCKET).getPublicUrl(storagePath);
    return data.publicUrl;
  }
  const localDir = path.join(process.cwd(), "public", "uploads", path.dirname(storagePath));
  await fs.mkdir(localDir, { recursive: true });
  const localPath = path.join(process.cwd(), "public", "uploads", storagePath);
  await fs.writeFile(localPath, buffer);
  return `/uploads/${storagePath}`;
}
