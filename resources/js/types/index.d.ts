import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
    [key: string]: unknown;
}

export interface User {
    id: number;
    uuid: string;
    name: string;
    email: string;
    username?: string | null;
    phone?: string | null;
    phone_verified_at?: string | null;
    date_of_birth?: string | null;
    country_code?: string | null;
    display_name?: string | null;
    avatar_url?: string | null;
    avatar?: string;
    timezone?: string;
    language?: string;
    status?: 'active' | 'suspended' | 'banned' | 'self_excluded';
    email_verified_at: string | null;
    terms_accepted_at?: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
}

export interface Country {
    code: string;
    name: string;
}
