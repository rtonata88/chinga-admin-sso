import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    Info,
    ShieldAlert,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

interface AuditLogEntry {
    id: number;
    event_type: string;
    severity: 'info' | 'warning' | 'critical';
    ip_address: string;
    location: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
}

interface AuditLogProps {
    logs: AuditLogEntry[];
    event_types: Record<string, string>;
}

function getSeverityBadge(severity: string) {
    switch (severity) {
        case 'critical':
            return (
                <Badge variant="destructive" className="gap-1">
                    <ShieldAlert className="h-3 w-3" />
                    Critical
                </Badge>
            );
        case 'warning':
            return (
                <Badge variant="secondary" className="gap-1 bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                    <AlertTriangle className="h-3 w-3" />
                    Warning
                </Badge>
            );
        default:
            return (
                <Badge variant="outline" className="gap-1">
                    <Info className="h-3 w-3" />
                    Info
                </Badge>
            );
    }
}

function getEventIcon(eventType: string) {
    switch (eventType) {
        case 'login':
            return <CheckCircle className="h-5 w-5 text-green-500" />;
        case 'login_failed':
        case 'account_locked':
            return <XCircle className="h-5 w-5 text-red-500" />;
        case 'mfa_enabled':
        case 'account_unlocked':
            return <ShieldCheck className="h-5 w-5 text-green-500" />;
        case 'mfa_disabled':
            return <ShieldAlert className="h-5 w-5 text-yellow-500" />;
        case 'password_changed':
        case 'password_reset':
        case 'email_changed':
            return <AlertTriangle className="h-5 w-5 text-yellow-500" />;
        default:
            return <Info className="h-5 w-5 text-blue-500" />;
    }
}

function formatDateTime(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function AuditLog({ logs, event_types }: AuditLogProps) {
    const [filter, setFilter] = useState<string>('all');

    const filteredLogs =
        filter === 'all'
            ? logs
            : logs.filter((log) => log.event_type === filter);

    return (
        <UserLayout title="Security Log">
            <Head title="Security Log" />
                <div className="space-y-6">
                    <HeadingSmall
                        title="Security Log"
                        description="Review recent security-related events on your account"
                    />

                    {/* Filter */}
                    <div className="flex items-center gap-4">
                        <Select value={filter} onValueChange={setFilter}>
                            <SelectTrigger className="w-[200px]">
                                <SelectValue placeholder="Filter by event" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All events</SelectItem>
                                {Object.entries(event_types).map(
                                    ([key, label]) => (
                                        <SelectItem key={key} value={key}>
                                            {label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                        <span className="text-sm text-muted-foreground">
                            {filteredLogs.length} event
                            {filteredLogs.length !== 1 ? 's' : ''}
                        </span>
                    </div>

                    {/* Logs list */}
                    <div className="space-y-3">
                        {filteredLogs.map((log) => (
                            <Card key={log.id}>
                                <CardContent className="flex items-start gap-4 p-4">
                                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                                        {getEventIcon(log.event_type)}
                                    </div>
                                    <div className="flex-1 space-y-1">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">
                                                {event_types[log.event_type] ||
                                                    log.event_type}
                                            </span>
                                            {getSeverityBadge(log.severity)}
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            {log.ip_address}
                                            {log.location &&
                                                ` - ${log.location}`}
                                        </div>
                                        {log.metadata &&
                                            Object.keys(log.metadata).length >
                                                0 && (
                                                <div className="text-xs text-muted-foreground">
                                                    {log.metadata.user_agent && (
                                                        <span>
                                                            {String(
                                                                log.metadata
                                                                    .user_agent,
                                                            ).substring(0, 60)}
                                                            ...
                                                        </span>
                                                    )}
                                                </div>
                                            )}
                                    </div>
                                    <div className="text-right text-sm text-muted-foreground">
                                        {formatDateTime(log.created_at)}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>

                    {filteredLogs.length === 0 && (
                        <p className="text-center text-muted-foreground">
                            No security events found.
                        </p>
                    )}

                    {logs.length > 0 && (
                        <p className="text-center text-xs text-muted-foreground">
                            Showing the last {logs.length} security events
                        </p>
                    )}
                </div>
        </UserLayout>
    );
}
