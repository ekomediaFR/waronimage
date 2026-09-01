// Shared client-side types mirroring API payloads (no Prisma import in the browser bundle).

export interface SiteDto {
  id: string;
  name: string;
  domain: string;
  sitemapUrl: string;
  brandColors: { primary?: string; secondary?: string; accent?: string };
  fontStyle: string;
  logoUrl?: string | null;
  wpApiUrl?: string | null;
  _count?: { pages: number; stockImages: number };
}

export interface AiImageDto {
  id: string;
  pageId: string;
  styleId: string;
  prompt: string;
  imageUrl: string;
  filename: string;
  altText: string;
  title: string;
  caption?: string | null;
  description?: string | null;
  matchScore: number;
  status: string;
  variantIndex: number;
  exportStatus?: string | null;
}

export interface StockImageDto {
  id: string;
  siteId: string;
  originalUrl: string;
  filename: string;
  altText?: string | null;
  title?: string | null;
  niche?: string | null;
  city?: string | null;
  tags: string[];
  quality?: number | null;
  matchScore?: number | null;
  status: string;
  assignments?: StockAssignmentDto[];
}

export interface StockAssignmentDto {
  id: string;
  pageId: string;
  stockImageId: string;
  matchScore: number;
  assignedBy: string;
  position: string;
  altText: string;
  filename: string;
  approved: boolean;
  exportStatus?: string | null;
  stockImage?: StockImageDto;
  page?: { id: string; title: string; url: string };
}

export interface PageDto {
  id: string;
  siteId: string;
  url: string;
  slug: string;
  title: string;
  cpt: string;
  niche: string;
  city?: string | null;
  pageIntent: string;
  contentSummary?: string | null;
  aiImages: AiImageDto[];
  stockAssignments: StockAssignmentDto[];
}

export interface ImageStyleDto {
  id: string;
  name: string;
  description: string;
  promptTemplate: string;
  niches: string[];
  formats: { ratio?: string; width?: number; height?: number };
  exampleUrls: string[];
}

export const NICHES = ["moving", "locksmith", "plumbing", "storage", "electrical", "cleaning", "other"];
export const CPTS = ["page", "post", "service", "city", "corridor"];
