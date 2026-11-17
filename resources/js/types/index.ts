import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    isStaging: boolean;
    inFlight?: number[];
    auth: Auth;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    is_admin?: boolean;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

/** One logged call to or from Pixelforge, as shown in the "Advanced" disclosures (already redacted server-side). */
export interface CallBody {
    value: unknown;
    truncated: boolean;
    shown: boolean;
}
export interface CallDetails {
    id: number;
    part?: 'request' | 'response' | 'both';
    channel: 'api' | 'webhook';
    action: string;
    outcome: string;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    method: string | null;
    url: string | null;
    request: { headers: Record<string, string> | null; body: CallBody } | null;
    response: { status: number | null; headers: Record<string, string> | null; body: CallBody } | null;
    error: { code: string | null; class: string | null; message: string | null } | null;
    // Admin page only.
    attempt?: number | null;
    ip?: string | null;
    event_id?: string | null;
}
