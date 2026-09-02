import { WpReviewPanel } from "@/components/WpReviewPanel";

export const dynamic = "force-dynamic";

export default function ReviewPage({ params }: { params: { siteId: string } }) {
  return <WpReviewPanel siteId={params.siteId} />;
}
