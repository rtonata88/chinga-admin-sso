import AppLayout from '@/components/acumatica/Layout/AppLayout';
import { type PropsWithChildren } from 'react';

interface Props {
    title?: string;
}

export default function AdminLayout({ children, title }: PropsWithChildren<Props>) {
    return (
        <AppLayout title={title}>
            {children}
        </AppLayout>
    );
}
