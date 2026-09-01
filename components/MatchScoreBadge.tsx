"use client";

import { Badge } from "@/components/ui/badge";
import { useI18n } from "./LanguageProvider";

/** Colored badge per the match-score interpretation table. */
export function MatchScoreBadge({ score }: { score: number }) {
  const { dict } = useI18n();
  const pctScore = `${Math.round(score * 100)}%`;
  if (score >= 0.85) return <Badge variant="green">{dict.score.perfect} · {pctScore}</Badge>;
  if (score >= 0.72) return <Badge variant="blue">{dict.score.strong} · {pctScore}</Badge>;
  if (score >= 0.55) return <Badge variant="yellow">{dict.score.possible} · {pctScore}</Badge>;
  return <Badge variant="red">{dict.score.weak} · {pctScore}</Badge>;
}
