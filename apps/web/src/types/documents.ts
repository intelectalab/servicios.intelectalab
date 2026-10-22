export type DocumentStatus = "ACTIVE" | "QUARANTINED" | "ARCHIVED";
export type DocumentVisibility = "INTERNAL" | "PORTAL";
export type AntivirusStatus = "PENDING" | "CLEAN" | "INFECTED" | "FAILED";
export type LinkType = "Party" | "Contract" | "Project" | "WorkOrder";

export interface DocumentLink {
  type: string;
  id: string;
  label: string;
  /** A dónde lleva; null si quien mira no ve ese registro. */
  href: string | null;
}

export interface DocumentFile {
  name: string;
  size: number;
  mime: string | null;
  sha256: string;
  antivirus: AntivirusStatus;
  engine: string | null;
}

export interface DocumentRow {
  id: string;
  title: string;
  category: string;
  status: DocumentStatus;
  visibility: DocumentVisibility;
  version: number;
  file: DocumentFile | null;
  links: DocumentLink[];
  creator: string | null;
  created_at: string;
  updated_at: string;
}

export interface DocumentVersion {
  version: number;
  name: string;
  size: number;
  sha256: string;
  antivirus: AntivirusStatus;
  engine: string | null;
  author: string | null;
  at: string;
}

export interface DocumentAccess {
  action: string;
  label: string;
  user: string | null;
  at: string;
  metadata: { version?: number; note?: string; expires_at?: string; title?: string };
}

export interface DocumentDetail extends DocumentRow {
  branch: string | null;
  archived_at: string | null;
  archived_by: string | null;
  lock_version: number;
  versions: DocumentVersion[];
  accesses: DocumentAccess[];
}

export interface LinkTarget {
  type: LinkType;
  id: string;
  label: string;
  /** Pertenece a un cliente: se puede mostrar en su portal. */
  customer: boolean;
}

export interface DocumentOptions {
  categories: string[];
  link_types: Record<LinkType, string>;
  targets: LinkTarget[];
}

export interface TemporaryUrl {
  url: string;
  expires_at: string;
  name: string;
  size: number;
  version: number;
}

/** Registro desde cuya ficha se sube: queda vinculado y no se elige. */
export interface FixedLink {
  type: LinkType;
  id: string;
  label: string;
  customer: boolean;
}
