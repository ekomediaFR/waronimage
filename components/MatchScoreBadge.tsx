import { Badge } from "@/components/ui/badge";

/** Colored badge per the match-score interpretation table. */
export function MatchScoreBadge({ score }: { score: number }) {
  const pctScore = `${Math.round(score * 100)}%`;
  if (score >= 0.85) return <Badge variant="green">Perfect · {pctScore}</Badge>;
  if (score >= 0.72) return <Badge variant="blue">Strong · {pctScore}</Badge>;
  if (score >= 0.55) return <Badge variant="yellow">Possible · {pctScore}</Badge>;
  return <Badge variant="red">Weak · {pctScore}</Badge>;
}
