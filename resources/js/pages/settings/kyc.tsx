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
import { Progress } from '@/components/ui/progress';
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
    AlertCircle,
    CheckCircle,
    Clock,
    FileText,
    Shield,
    Upload,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

interface KycDocument {
    uuid: string;
    document_type: string;
    document_type_label: string;
    status: 'pending' | 'approved' | 'rejected';
    rejection_reason: string | null;
    verified_at: string | null;
    document_expiry: string | null;
    is_expired: boolean;
    is_expiring_soon: boolean;
    created_at: string;
}

interface KycStatus {
    level: number;
    level_name: string;
    verified_at: string | null;
    verification_status: {
        email_verified: boolean;
        phone_verified: boolean;
        date_of_birth_provided: boolean;
        identity_document_verified: boolean;
        selfie_verified: boolean;
        proof_of_address_verified: boolean;
        source_of_funds_verified: boolean;
    };
    documents: {
        approved: number;
        pending: number;
        rejected: number;
    };
    next_level_requirements: string[];
    can_upgrade: boolean;
}

interface KycLimits {
    daily_deposit: number | null;
    monthly_deposit: number | null;
    withdrawal: number | null;
    can_play: boolean;
}

interface DocumentType {
    value: string;
    label: string;
}

interface KycProps {
    kyc: KycStatus;
    limits: KycLimits;
    documents: KycDocument[];
    document_types: DocumentType[];
}

function getLevelBadgeVariant(level: number) {
    switch (level) {
        case 0:
            return 'destructive';
        case 1:
            return 'secondary';
        case 2:
            return 'default';
        case 3:
            return 'default';
        default:
            return 'outline';
    }
}

function getStatusIcon(status: string) {
    switch (status) {
        case 'approved':
            return <CheckCircle className="h-4 w-4 text-green-500" />;
        case 'rejected':
            return <XCircle className="h-4 w-4 text-red-500" />;
        case 'pending':
            return <Clock className="h-4 w-4 text-yellow-500" />;
        default:
            return <FileText className="h-4 w-4" />;
    }
}

function formatLimit(limit: number | null): string {
    if (limit === null) return 'No limit';
    return `NAD ${limit.toLocaleString()}`;
}

export default function Kyc({ kyc, limits, documents, document_types }: KycProps) {
    const [showUploadDialog, setShowUploadDialog] = useState(false);
    const [selectedType, setSelectedType] = useState('');
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [uploading, setUploading] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const handleUpload = () => {
        if (!selectedFile || !selectedType) return;

        setUploading(true);
        setErrors({});

        const formData = new FormData();
        formData.append('file', selectedFile);
        formData.append('document_type', selectedType);

        router.post('/api/v1/kyc/documents', formData, {
            forceFormData: true,
            onSuccess: () => {
                setShowUploadDialog(false);
                setSelectedFile(null);
                setSelectedType('');
                router.reload();
            },
            onError: (errors) => {
                setErrors(errors);
            },
            onFinish: () => {
                setUploading(false);
            },
        });
    };

    const handleDelete = (uuid: string) => {
        if (confirm('Are you sure you want to delete this document?')) {
            router.delete(`/api/v1/kyc/documents/${uuid}`, {
                onSuccess: () => {
                    router.reload();
                },
            });
        }
    };

    const levelProgress = (kyc.level / 3) * 100;

    return (
        <UserLayout title="KYC Verification">
            <Head title="KYC Verification" />
                <div className="space-y-6">
                    <HeadingSmall
                        title="KYC Verification"
                        description="Verify your identity to unlock full platform features"
                    />

                    {/* Current Level */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Shield className="h-5 w-5" />
                                    <CardTitle>Verification Level</CardTitle>
                                </div>
                                <Badge variant={getLevelBadgeVariant(kyc.level)}>
                                    {kyc.level_name}
                                </Badge>
                            </div>
                            <CardDescription>
                                Level {kyc.level} of 3
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Progress value={levelProgress} className="h-2" />

                            {/* Verification checklist */}
                            <div className="grid gap-2 text-sm">
                                <div className="flex items-center gap-2">
                                    {kyc.verification_status.email_verified ? (
                                        <CheckCircle className="h-4 w-4 text-green-500" />
                                    ) : (
                                        <XCircle className="h-4 w-4 text-muted-foreground" />
                                    )}
                                    <span>Email verified</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    {kyc.verification_status.phone_verified ? (
                                        <CheckCircle className="h-4 w-4 text-green-500" />
                                    ) : (
                                        <XCircle className="h-4 w-4 text-muted-foreground" />
                                    )}
                                    <span>Phone verified</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    {kyc.verification_status.date_of_birth_provided ? (
                                        <CheckCircle className="h-4 w-4 text-green-500" />
                                    ) : (
                                        <XCircle className="h-4 w-4 text-muted-foreground" />
                                    )}
                                    <span>Date of birth provided</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    {kyc.verification_status.identity_document_verified ? (
                                        <CheckCircle className="h-4 w-4 text-green-500" />
                                    ) : (
                                        <XCircle className="h-4 w-4 text-muted-foreground" />
                                    )}
                                    <span>Identity document verified</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    {kyc.verification_status.proof_of_address_verified ? (
                                        <CheckCircle className="h-4 w-4 text-green-500" />
                                    ) : (
                                        <XCircle className="h-4 w-4 text-muted-foreground" />
                                    )}
                                    <span>Proof of address verified</span>
                                </div>
                            </div>

                            {/* Next level requirements */}
                            {kyc.next_level_requirements.length > 0 && (
                                <div className="rounded-lg bg-muted p-4">
                                    <p className="mb-2 font-medium">
                                        To upgrade to the next level:
                                    </p>
                                    <ul className="list-inside list-disc space-y-1 text-sm text-muted-foreground">
                                        {kyc.next_level_requirements.map(
                                            (req, i) => (
                                                <li key={i}>{req}</li>
                                            ),
                                        )}
                                    </ul>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Account Limits */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Account Limits</CardTitle>
                            <CardDescription>
                                Your current transaction limits based on
                                verification level
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Daily Deposit
                                    </p>
                                    <p className="text-lg font-semibold">
                                        {formatLimit(limits.daily_deposit)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Monthly Deposit
                                    </p>
                                    <p className="text-lg font-semibold">
                                        {formatLimit(limits.monthly_deposit)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Withdrawal Limit
                                    </p>
                                    <p className="text-lg font-semibold">
                                        {formatLimit(limits.withdrawal)}
                                    </p>
                                </div>
                            </div>
                            {!limits.can_play && (
                                <div className="mt-4 flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-destructive">
                                    <AlertCircle className="h-4 w-4" />
                                    <span className="text-sm">
                                        Complete basic verification to start
                                        playing
                                    </span>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Documents Section */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle>Documents</CardTitle>
                                    <CardDescription>
                                        Upload and manage your verification
                                        documents
                                    </CardDescription>
                                </div>
                                <Button onClick={() => setShowUploadDialog(true)}>
                                    <Upload className="mr-2 h-4 w-4" />
                                    Upload Document
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {documents.length === 0 ? (
                                <p className="text-center text-muted-foreground">
                                    No documents uploaded yet.
                                </p>
                            ) : (
                                <div className="space-y-4">
                                    {documents.map((doc) => (
                                        <div
                                            key={doc.uuid}
                                            className="flex items-center justify-between rounded-lg border p-4"
                                        >
                                            <div className="flex items-center gap-4">
                                                {getStatusIcon(doc.status)}
                                                <div>
                                                    <p className="font-medium">
                                                        {doc.document_type_label}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        Uploaded{' '}
                                                        {new Date(
                                                            doc.created_at,
                                                        ).toLocaleDateString()}
                                                    </p>
                                                    {doc.rejection_reason && (
                                                        <p className="text-sm text-destructive">
                                                            Rejected:{' '}
                                                            {doc.rejection_reason}
                                                        </p>
                                                    )}
                                                    {doc.is_expiring_soon &&
                                                        !doc.is_expired && (
                                                            <p className="text-sm text-yellow-600">
                                                                Expiring soon
                                                            </p>
                                                        )}
                                                    {doc.is_expired && (
                                                        <p className="text-sm text-destructive">
                                                            Expired
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Badge
                                                    variant={
                                                        doc.status === 'approved'
                                                            ? 'default'
                                                            : doc.status ===
                                                                  'rejected'
                                                              ? 'destructive'
                                                              : 'secondary'
                                                    }
                                                >
                                                    {doc.status}
                                                </Badge>
                                                {doc.status === 'pending' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            handleDelete(doc.uuid)
                                                        }
                                                    >
                                                        Delete
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Upload Dialog */}
                <Dialog open={showUploadDialog} onOpenChange={setShowUploadDialog}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Upload Document</DialogTitle>
                            <DialogDescription>
                                Upload a document for verification. Accepted
                                formats: JPEG, PNG, WebP, PDF (max 10MB)
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <Label>Document Type</Label>
                                <Select
                                    value={selectedType}
                                    onValueChange={setSelectedType}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select document type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {document_types.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.document_type && (
                                    <p className="text-sm text-destructive">
                                        {errors.document_type}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label>File</Label>
                                <Input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,application/pdf"
                                    onChange={(e) =>
                                        setSelectedFile(
                                            e.target.files?.[0] || null,
                                        )
                                    }
                                />
                                {errors.file && (
                                    <p className="text-sm text-destructive">
                                        {errors.file}
                                    </p>
                                )}
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setShowUploadDialog(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={handleUpload}
                                disabled={
                                    uploading || !selectedFile || !selectedType
                                }
                            >
                                {uploading ? 'Uploading...' : 'Upload'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
        </UserLayout>
    );
}
