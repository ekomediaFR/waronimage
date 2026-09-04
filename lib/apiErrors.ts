import { NextResponse } from "next/server";
import { Prisma } from "@prisma/client";

const DB_HINT =
  "Database not connected — set DATABASE_URL and DIRECT_URL (Vercel → Settings → Environment Variables), run `npx prisma db push`, then redeploy.";

/** Map a route error to a JSON response — 503 with a setup hint when the database is the problem. */
export function apiError(err: unknown): NextResponse {
  const e = err as Error & { code?: string };
  const isDbDown =
    e instanceof Prisma.PrismaClientInitializationError ||
    e?.name === "PrismaClientInitializationError" ||
    /P1000|P1001|P1002|P1003|P1010/.test(e?.code || "") ||
    /Environment variable not found: (DATABASE_URL|DIRECT_URL)/.test(e?.message || "") ||
    /Can't reach database server/i.test(e?.message || "");
  if (isDbDown) {
    console.error("[waronimage] database unavailable:", e?.message);
    return NextResponse.json({ error: DB_HINT }, { status: 503 });
  }
  console.error("[waronimage] API error:", e);
  return NextResponse.json({ error: e?.message || "Internal error" }, { status: 500 });
}
