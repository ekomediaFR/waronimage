import { PrismaClient } from "@prisma/client";

// The schema declares directUrl = env("DIRECT_URL") for Supabase pooling; when a
// deployment only sets DATABASE_URL, fall back to it so the client still boots.
if (!process.env.DIRECT_URL && process.env.DATABASE_URL) {
  process.env.DIRECT_URL = process.env.DATABASE_URL;
}

const globalForPrisma = globalThis as unknown as { prisma?: PrismaClient };

export const prisma =
  globalForPrisma.prisma ??
  new PrismaClient({
    log: process.env.NODE_ENV === "development" ? ["warn", "error"] : ["error"],
  });

if (process.env.NODE_ENV !== "production") globalForPrisma.prisma = prisma;

/** Run a query, returning a fallback instead of throwing when the DB is unreachable/unconfigured. */
export async function safeQuery<T>(fn: () => Promise<T>, fallback: T): Promise<T> {
  try {
    return await fn();
  } catch (err) {
    console.error("[waronimage] database query failed:", (err as Error).message);
    return fallback;
  }
}
