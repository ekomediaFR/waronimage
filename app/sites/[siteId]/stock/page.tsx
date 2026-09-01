import { StockManager } from "@/components/StockManager";

export default function StockPage({ params }: { params: { siteId: string } }) {
  return <StockManager siteId={params.siteId} />;
}
