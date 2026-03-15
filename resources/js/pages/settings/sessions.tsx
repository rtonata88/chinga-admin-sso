import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import {
    Laptop,
    LogOut,
    Monitor,
    Smartphone,
    Tablet,
} from 'lucide-react';
import { useState } from 'react';

interface Session {
    id: number;
    device_info: string;
    device_type: string;
    browser: string;
    platform: string;
    location: string | null;
    ip_address: string;
    is_current: boolean;
    last_active_at: string;
    created_at: string;
}

interface SessionStats {
    total_sessions: number;
    active_today: number;
    unique_devices: number;
}

interface SessionsProps {
    sessions: Session[];
    stats: SessionStats;
}

function getDeviceIcon(deviceType: string) {
    switch (deviceType.toLowerCase()) {
        case 'mobile':
            return <Smartphone className="h-5 w-5" />;
        case 'tablet':
            return <Tablet className="h-5 w-5" />;
        case 'desktop':
            return <Monitor className="h-5 w-5" />;
        default:
            return <Laptop className="h-5 w-5" />;
    }
}

function formatRelativeTime(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
    if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
    return date.toLocaleDateString();
}

export default function Sessions({ sessions, stats }: SessionsProps) {
    const [confirmLogoutAll, setConfirmLogoutAll] = useState(false);
    const [password, setPassword] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const revokeSession = (sessionId: number) => {
        router.delete(`/settings/sessions/${sessionId}`, {
            preserveScroll: true,
        });
    };

    const revokeAllSessions = () => {
        setProcessing(true);
        setErrors({});

        router.delete('/settings/sessions', {
            data: { password },
            preserveScroll: true,
            onSuccess: () => {
                setConfirmLogoutAll(false);
                setPassword('');
            },
            onError: (errors) => {
                setErrors(errors);
            },
            onFinish: () => {
                setProcessing(false);
            },
        });
    };

    return (
        <UserLayout title="Sessions">
            <Head title="Active Sessions" />
                <div className="space-y-6">
                    <HeadingSmall
                        title="Active Sessions"
                        description="Manage your active sessions across different devices"
                    />

                    {/* Stats */}
                    <div className="grid grid-cols-3 gap-4">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardDescription>Total Sessions</CardDescription>
                                <CardTitle className="text-2xl">
                                    {stats.total_sessions}
                                </CardTitle>
                            </CardHeader>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardDescription>Active Today</CardDescription>
                                <CardTitle className="text-2xl">
                                    {stats.active_today}
                                </CardTitle>
                            </CardHeader>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardDescription>Unique Devices</CardDescription>
                                <CardTitle className="text-2xl">
                                    {stats.unique_devices}
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    </div>

                    {/* Logout all button */}
                    {sessions.length > 1 && (
                        <div>
                            <Button
                                variant="destructive"
                                onClick={() => setConfirmLogoutAll(true)}
                            >
                                <LogOut className="mr-2 h-4 w-4" />
                                Log out all other sessions
                            </Button>
                        </div>
                    )}

                    {/* Sessions list */}
                    <div className="space-y-4">
                        {sessions.map((session) => (
                            <Card key={session.id}>
                                <CardContent className="flex items-center justify-between p-4">
                                    <div className="flex items-center gap-4">
                                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                                            {getDeviceIcon(session.device_type)}
                                        </div>
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">
                                                    {session.browser} on{' '}
                                                    {session.platform}
                                                </span>
                                                {session.is_current && (
                                                    <Badge variant="secondary">
                                                        Current
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                {session.ip_address}
                                                {session.location &&
                                                    ` - ${session.location}`}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Last active:{' '}
                                                {formatRelativeTime(
                                                    session.last_active_at,
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    {!session.is_current && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                revokeSession(session.id)
                                            }
                                        >
                                            <LogOut className="h-4 w-4" />
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>

                    {sessions.length === 0 && (
                        <p className="text-center text-muted-foreground">
                            No active sessions found.
                        </p>
                    )}
                </div>

                {/* Confirm logout all dialog */}
                <Dialog open={confirmLogoutAll} onOpenChange={setConfirmLogoutAll}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Log out all other sessions?</DialogTitle>
                            <DialogDescription>
                                This will log you out of all other devices and
                                browsers. You will remain logged in on this device.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <Label htmlFor="password">
                                    Confirm your password
                                </Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={password}
                                    onChange={(e) => setPassword(e.target.value)}
                                    placeholder="Enter your password"
                                />
                                {errors.password && (
                                    <p className="text-sm text-destructive">
                                        {errors.password}
                                    </p>
                                )}
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setConfirmLogoutAll(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={revokeAllSessions}
                                disabled={processing || !password}
                            >
                                {processing ? 'Logging out...' : 'Log out all'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
        </UserLayout>
    );
}
