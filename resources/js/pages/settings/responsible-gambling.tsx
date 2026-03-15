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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    Clock,
    DollarSign,
    History,
    Shield,
} from 'lucide-react';
import { useState } from 'react';

interface DepositLimits {
    daily: number | null;
    weekly: number | null;
    monthly: number | null;
}

interface PendingLimits {
    daily: number | null;
    weekly: number | null;
    monthly: number | null;
    effective_at: string | null;
}

interface SessionLimits {
    time_limit_minutes: number | null;
    loss_limit: number | null;
}

interface LoginTimeRestriction {
    start: string;
    end: string;
    allowed_now: boolean;
}

interface SelfExclusionInfo {
    type: 'temporary' | 'permanent';
    starts_at: string;
    ends_at: string | null;
    remaining_days: number | null;
    duration_label: string;
}

interface Status {
    deposit_limits: DepositLimits;
    pending_limits: PendingLimits | null;
    session_limits: SessionLimits;
    reality_check_interval_minutes: number | null;
    wager_limits: { daily: number | null; weekly: number | null };
    login_time_restriction: LoginTimeRestriction | null;
    self_exclusion: SelfExclusionInfo | null;
    is_excluded: boolean;
}

interface ExclusionHistoryItem {
    id: number;
    type: 'temporary' | 'permanent';
    starts_at: string;
    ends_at: string | null;
    duration_label: string;
    is_active: boolean;
    was_revoked: boolean;
    created_at: string;
}

interface SelectOption {
    value: string | number | null;
    label: string;
}

interface Options {
    reality_check_intervals: SelectOption[];
    session_time_limits: SelectOption[];
    self_exclusion_durations: SelectOption[];
}

interface ResponsibleGamblingProps {
    status: Status;
    exclusion_history: ExclusionHistoryItem[];
    options: Options;
}

function formatCurrency(amount: number | null): string {
    if (amount === null) return 'No limit';
    return `NAD ${amount.toLocaleString()}`;
}

export default function ResponsibleGambling({
    status,
    exclusion_history,
    options,
}: ResponsibleGamblingProps) {
    const [showLimitsDialog, setShowLimitsDialog] = useState(false);
    const [showExcludeDialog, setShowExcludeDialog] = useState(false);
    const [saving, setSaving] = useState(false);

    // Deposit limits form
    const [dailyLimit, setDailyLimit] = useState<string>(
        status.deposit_limits.daily?.toString() || '',
    );
    const [weeklyLimit, setWeeklyLimit] = useState<string>(
        status.deposit_limits.weekly?.toString() || '',
    );
    const [monthlyLimit, setMonthlyLimit] = useState<string>(
        status.deposit_limits.monthly?.toString() || '',
    );

    // Self-exclusion form
    const [excludeDuration, setExcludeDuration] = useState<string>('');
    const [excludeReason, setExcludeReason] = useState<string>('');
    const [confirmExclude, setConfirmExclude] = useState(false);

    const handleSaveLimits = () => {
        setSaving(true);

        router.put(
            '/api/v1/responsible-gambling/deposit-limits',
            {
                daily: dailyLimit ? parseFloat(dailyLimit) : null,
                weekly: weeklyLimit ? parseFloat(weeklyLimit) : null,
                monthly: monthlyLimit ? parseFloat(monthlyLimit) : null,
            },
            {
                onSuccess: () => {
                    setShowLimitsDialog(false);
                    router.reload();
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    const handleCancelPending = () => {
        router.delete('/api/v1/responsible-gambling/pending-limits', {
            onSuccess: () => router.reload(),
        });
    };

    const handleSelfExclude = () => {
        if (!confirmExclude || !excludeDuration) return;

        setSaving(true);

        router.post(
            '/api/v1/responsible-gambling/self-exclude',
            {
                duration: excludeDuration,
                reason: excludeReason || null,
                confirm: true,
            },
            {
                onSuccess: () => {
                    setShowExcludeDialog(false);
                    router.reload();
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    const handleUpdateRealityCheck = (value: string) => {
        router.put(
            '/api/v1/responsible-gambling/reality-check',
            {
                interval_minutes: value === 'none' ? null : parseInt(value),
            },
            {
                onSuccess: () => router.reload(),
            },
        );
    };

    const handleUpdateSessionLimit = (value: string) => {
        router.put(
            '/api/v1/responsible-gambling/session-limits',
            {
                time_limit_minutes: value === 'none' ? null : parseInt(value),
            },
            {
                onSuccess: () => router.reload(),
            },
        );
    };

    return (
        <UserLayout title="Responsible Gambling">
            <Head title="Responsible Gambling" />
                <div className="space-y-6">
                    <HeadingSmall
                        title="Responsible Gambling"
                        description="Manage your gambling limits and self-exclusion options"
                    />

                    {/* Active Self-Exclusion Warning */}
                    {status.is_excluded && status.self_exclusion && (
                        <Card className="border-destructive">
                            <CardHeader>
                                <div className="flex items-center gap-2 text-destructive">
                                    <Ban className="h-5 w-5" />
                                    <CardTitle>Self-Exclusion Active</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <p className="text-muted-foreground">
                                    You have an active{' '}
                                    {status.self_exclusion.type} self-exclusion.
                                    {status.self_exclusion.type ===
                                    'temporary' ? (
                                        <>
                                            {' '}
                                            It will end{' '}
                                            {status.self_exclusion.ends_at
                                                ? new Date(
                                                      status.self_exclusion.ends_at,
                                                  ).toLocaleDateString()
                                                : 'when removed'}
                                            .
                                            {status.self_exclusion
                                                .remaining_days !== null && (
                                                <strong>
                                                    {' '}
                                                    (
                                                    {
                                                        status.self_exclusion
                                                            .remaining_days
                                                    }{' '}
                                                    days remaining)
                                                </strong>
                                            )}
                                        </>
                                    ) : (
                                        ' This exclusion is permanent and cannot be undone.'
                                    )}
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {/* Deposit Limits */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <DollarSign className="h-5 w-5" />
                                    <CardTitle>Deposit Limits</CardTitle>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setShowLimitsDialog(true)}
                                    disabled={status.is_excluded}
                                >
                                    Edit Limits
                                </Button>
                            </div>
                            <CardDescription>
                                Set maximum deposit amounts to help manage your
                                spending
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Daily Limit
                                    </p>
                                    <p className="text-lg font-semibold">
                                        {formatCurrency(
                                            status.deposit_limits.daily,
                                        )}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Weekly Limit
                                    </p>
                                    <p className="text-lg font-semibold">
                                        {formatCurrency(
                                            status.deposit_limits.weekly,
                                        )}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Monthly Limit
                                    </p>
                                    <p className="text-lg font-semibold">
                                        {formatCurrency(
                                            status.deposit_limits.monthly,
                                        )}
                                    </p>
                                </div>
                            </div>

                            {/* Pending Limits Notice */}
                            {status.pending_limits && (
                                <div className="mt-4 rounded-lg bg-yellow-50 p-4 dark:bg-yellow-900/20">
                                    <div className="flex items-start gap-2">
                                        <Clock className="mt-0.5 h-4 w-4 text-yellow-600" />
                                        <div className="flex-1">
                                            <p className="font-medium text-yellow-800 dark:text-yellow-200">
                                                Pending Limit Increase
                                            </p>
                                            <p className="text-sm text-yellow-700 dark:text-yellow-300">
                                                New limits will take effect on{' '}
                                                {new Date(
                                                    status.pending_limits
                                                        .effective_at!,
                                                ).toLocaleString()}
                                            </p>
                                            <div className="mt-2 text-sm">
                                                {status.pending_limits.daily !==
                                                    null && (
                                                    <p>
                                                        Daily:{' '}
                                                        {formatCurrency(
                                                            status.pending_limits
                                                                .daily,
                                                        )}
                                                    </p>
                                                )}
                                                {status.pending_limits
                                                    .weekly !== null && (
                                                    <p>
                                                        Weekly:{' '}
                                                        {formatCurrency(
                                                            status.pending_limits
                                                                .weekly,
                                                        )}
                                                    </p>
                                                )}
                                                {status.pending_limits
                                                    .monthly !== null && (
                                                    <p>
                                                        Monthly:{' '}
                                                        {formatCurrency(
                                                            status.pending_limits
                                                                .monthly,
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="mt-2"
                                                onClick={handleCancelPending}
                                            >
                                                Cancel Increase
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Session Controls */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <Clock className="h-5 w-5" />
                                <CardTitle>Session Controls</CardTitle>
                            </div>
                            <CardDescription>
                                Manage your gaming session duration and
                                reminders
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Session Time Limit</Label>
                                    <Select
                                        value={
                                            status.session_limits
                                                .time_limit_minutes?.toString() ||
                                            'none'
                                        }
                                        onValueChange={handleUpdateSessionLimit}
                                        disabled={status.is_excluded}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select limit" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.session_time_limits.map(
                                                (opt) => (
                                                    <SelectItem
                                                        key={
                                                            opt.value?.toString() ||
                                                            'none'
                                                        }
                                                        value={
                                                            opt.value?.toString() ||
                                                            'none'
                                                        }
                                                    >
                                                        {opt.label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        You'll be logged out after this duration
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <Label>Reality Check Reminder</Label>
                                    <Select
                                        value={
                                            status.reality_check_interval_minutes?.toString() ||
                                            'none'
                                        }
                                        onValueChange={handleUpdateRealityCheck}
                                        disabled={status.is_excluded}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select interval" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.reality_check_intervals.map(
                                                (opt) => (
                                                    <SelectItem
                                                        key={
                                                            opt.value?.toString() ||
                                                            'none'
                                                        }
                                                        value={
                                                            opt.value?.toString() ||
                                                            'none'
                                                        }
                                                    >
                                                        {opt.label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        Receive periodic reminders during play
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Self-Exclusion */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <Shield className="h-5 w-5" />
                                <CardTitle>Self-Exclusion</CardTitle>
                            </div>
                            <CardDescription>
                                Take a break from gambling by temporarily or
                                permanently excluding yourself
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {status.is_excluded ? (
                                <div className="flex items-center gap-2 text-muted-foreground">
                                    <Ban className="h-4 w-4" />
                                    <span>
                                        You currently have an active
                                        self-exclusion
                                    </span>
                                </div>
                            ) : (
                                <>
                                    <p className="mb-4 text-sm text-muted-foreground">
                                        Self-exclusion prevents you from
                                        accessing all gaming features. Temporary
                                        exclusions have a cooling-off period
                                        before they can be removed. Permanent
                                        exclusions cannot be undone.
                                    </p>
                                    <Button
                                        variant="destructive"
                                        onClick={() =>
                                            setShowExcludeDialog(true)
                                        }
                                    >
                                        <Ban className="mr-2 h-4 w-4" />
                                        Self-Exclude
                                    </Button>
                                </>
                            )}
                        </CardContent>
                    </Card>

                    {/* Exclusion History */}
                    {exclusion_history.length > 0 && (
                        <Card>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <History className="h-5 w-5" />
                                    <CardTitle>Exclusion History</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-3">
                                    {exclusion_history.map((item) => (
                                        <div
                                            key={item.id}
                                            className="flex items-center justify-between rounded-lg border p-3"
                                        >
                                            <div>
                                                <p className="font-medium">
                                                    {item.duration_label}{' '}
                                                    exclusion
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    Started{' '}
                                                    {new Date(
                                                        item.starts_at,
                                                    ).toLocaleDateString()}
                                                    {item.ends_at &&
                                                        ` - Ended ${new Date(item.ends_at).toLocaleDateString()}`}
                                                </p>
                                            </div>
                                            <Badge
                                                variant={
                                                    item.is_active
                                                        ? 'destructive'
                                                        : item.was_revoked
                                                          ? 'secondary'
                                                          : 'default'
                                                }
                                            >
                                                {item.is_active
                                                    ? 'Active'
                                                    : item.was_revoked
                                                      ? 'Revoked'
                                                      : 'Completed'}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Edit Limits Dialog */}
                <Dialog
                    open={showLimitsDialog}
                    onOpenChange={setShowLimitsDialog}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Edit Deposit Limits</DialogTitle>
                            <DialogDescription>
                                Set your deposit limits. Decreases take effect
                                immediately. Increases require a 24-hour
                                cooling-off period.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <Label htmlFor="daily">
                                    Daily Limit (NAD)
                                </Label>
                                <Input
                                    id="daily"
                                    type="number"
                                    min="0"
                                    placeholder="No limit"
                                    value={dailyLimit}
                                    onChange={(e) =>
                                        setDailyLimit(e.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="weekly">
                                    Weekly Limit (NAD)
                                </Label>
                                <Input
                                    id="weekly"
                                    type="number"
                                    min="0"
                                    placeholder="No limit"
                                    value={weeklyLimit}
                                    onChange={(e) =>
                                        setWeeklyLimit(e.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="monthly">
                                    Monthly Limit (NAD)
                                </Label>
                                <Input
                                    id="monthly"
                                    type="number"
                                    min="0"
                                    placeholder="No limit"
                                    value={monthlyLimit}
                                    onChange={(e) =>
                                        setMonthlyLimit(e.target.value)
                                    }
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setShowLimitsDialog(false)}
                            >
                                Cancel
                            </Button>
                            <Button onClick={handleSaveLimits} disabled={saving}>
                                {saving ? 'Saving...' : 'Save Limits'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Self-Exclude Dialog */}
                <Dialog
                    open={showExcludeDialog}
                    onOpenChange={setShowExcludeDialog}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2 text-destructive">
                                <AlertTriangle className="h-5 w-5" />
                                Self-Exclusion
                            </DialogTitle>
                            <DialogDescription>
                                This action will block your access to all gaming
                                features for the selected duration.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <Label>Exclusion Duration</Label>
                                <Select
                                    value={excludeDuration}
                                    onValueChange={setExcludeDuration}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select duration" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.self_exclusion_durations.map(
                                            (opt) => (
                                                <SelectItem
                                                    key={opt.value?.toString()}
                                                    value={opt.value?.toString() || ''}
                                                >
                                                    {opt.label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="reason">
                                    Reason (optional)
                                </Label>
                                <Input
                                    id="reason"
                                    placeholder="Why are you self-excluding?"
                                    value={excludeReason}
                                    onChange={(e) =>
                                        setExcludeReason(e.target.value)
                                    }
                                />
                            </div>

                            <div className="rounded-lg bg-destructive/10 p-4">
                                <div className="flex items-start gap-2">
                                    <input
                                        type="checkbox"
                                        id="confirm"
                                        checked={confirmExclude}
                                        onChange={(e) =>
                                            setConfirmExclude(e.target.checked)
                                        }
                                        className="mt-1"
                                    />
                                    <label
                                        htmlFor="confirm"
                                        className="text-sm"
                                    >
                                        I understand that this action will
                                        immediately block my access to all
                                        gaming features.
                                        {excludeDuration === 'permanent' && (
                                            <strong className="text-destructive">
                                                {' '}
                                                Permanent exclusions cannot be
                                                undone.
                                            </strong>
                                        )}
                                    </label>
                                </div>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setShowExcludeDialog(false);
                                    setConfirmExclude(false);
                                    setExcludeDuration('');
                                    setExcludeReason('');
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={handleSelfExclude}
                                disabled={
                                    saving ||
                                    !confirmExclude ||
                                    !excludeDuration
                                }
                            >
                                {saving ? 'Processing...' : 'Confirm Exclusion'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
        </UserLayout>
    );
}
