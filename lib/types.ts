// Shared client-side types mirroring API payloads (no Prisma import in the browser bundle).

export interface SiteDto {
  id: string;
  name: string;
  domain: string;
  sitemapUrl?: string | null;
  brandColors?: { primary?: string; secondary?: string; accent?: string } | null;
  fontStyle: string;
  logoUrl?: string | null;
  wpApiUrl?: string | null;
  wpAuthMethod?: string | null;
  language?: string;
  _count?: { pages: number; stockImages: number; wpPages?: number; wpMedia?: number; assignments?: number };
}

// ─── WP sync DTOs (Sync & Match / Review / WP Export tabs) ───

export interface WpStatsDto {
  pagesCount: number;
  mediaCount: number;
  assigned: number;
  approved: number;
  exported: number;
  pending: number;
}

export interface WpMediaLiteDto {
  id: string;
  wpId?: number;
  thumbUrl?: string | null;
  sourceUrl: string;
  filename: string;
  width?: number | null;
  height?: number | null;
  isUsable?: boolean;
  usageCount?: number;
}

export interface WpPageRowDto {
  id: string;
  wpId: number;
  wpType: string;
  title: string;
  slug: string;
  url: string;
  status: string;
  niche?: string | null;
  city?: string | null;
  currentFeaturedMediaId?: number | null;
  assignment: {
    id: string;
    matchScore: number;
    approved: boolean;
    exported: boolean;
    assignedBy: string;
    media: { id: string; thumbUrl?: string | null; sourceUrl: string; filename: string };
  } | null;
}

export interface SyncLogDto {
  id: string;
  type: string;
  status: string;
  pagesCount?: number | null;
  mediaCount?: number | null;
  matchCount?: number | null;
  exportCount?: number | null;
  errorMsg?: string | null;
  startedAt: string;
  finishedAt?: string | null;
}

export interface WpOverviewDto {
  stats: WpStatsDto;
  pages: WpPageRowDto[];
  media: WpMediaLiteDto[];
  lastLogs: SyncLogDto[];
}

export interface WpAssignmentDto {
  id: string;
  siteId: string;
  pageId: string;
  mediaId: string;
  matchScore: number;
  matchReason?: string | null;
  assignedBy: string;
  seoAltText?: string | null;
  seoTitle?: string | null;
  seoCaption?: string | null;
  seoDescription?: string | null;
  seoFilename?: string | null;
  assignmentType: string;
  approved: boolean;
  exported: boolean;
  exportedAt?: string | null;
  exportError?: string | null;
  page: {
    id: string;
    wpId: number;
    wpType: string;
    title: string;
    slug: string;
    url: string;
    status: string;
    niche?: string | null;
    city?: string | null;
    currentFeaturedMediaId?: number | null;
  };
  media: {
    id: string;
    wpId: number;
    thumbUrl?: string | null;
    sourceUrl: string;
    filename: string;
    width?: number | null;
    height?: number | null;
    wpAltText?: string | null;
  };
}

export interface WpConnectionTestDto {
  ok: boolean;
  authenticated: boolean;
  via?: "core" | "bridge" | null;
  bridgeVersion?: string | null;
  siteName: string | null;
  wpUrl: string;
  postTypes: string[];
  pagesCount: number | null;
  mediaCount: number | null;
  error: string | null;
}

export interface ExportStatusDto {
  approved: number;
  exported: number;
  pending: number;
  running: boolean;
  lastLog?: SyncLogDto | null;
  errors: { assignmentId: string; pageTitle: string; pageUrl: string; error: string | null }[];
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
