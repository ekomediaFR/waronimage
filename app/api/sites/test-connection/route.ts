import { NextRequest, NextResponse } from "next/server";
import { testWpConnection } from "@/lib/wpRest";

export const dynamic = "force-dynamic";
export const maxDuration = 60;

/** POST — probe a WP site with raw (not yet saved) credentials, for the add-site form. */
export async function POST(req: NextRequest) {
  const body = await req.json().catch(() => ({}));
  const { domain, wpApiUrl, wpAuthMethod, wpJwtToken, wpAppUser, wpAppPassword, wpAuthToken } = body;
  if (!domain && !wpApiUrl) {
    return NextResponse.json({ error: "domain or wpApiUrl required" }, { status: 400 });
  }
  const result = await testWpConnection({
    domain: domain || "",
    wpApiUrl,
    wpAuthMethod,
    wpJwtToken,
    wpAppUser,
    wpAppPassword,
    wpAuthToken,
  });
  return NextResponse.json(result);
}
