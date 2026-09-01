import { GenerationManager } from "@/components/GenerationManager";

export default function AiImagesPage({ params }: { params: { siteId: string } }) {
  return <GenerationManager siteId={params.siteId} />;
}
