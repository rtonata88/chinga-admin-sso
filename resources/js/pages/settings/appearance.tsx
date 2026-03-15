import { Head } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import UserLayout from '@/layouts/user-layout';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <UserLayout title="Appearance">
            <Head title="Appearance settings" />

                <div className="space-y-6">
                    <HeadingSmall
                        title="Appearance settings"
                        description="Update your account's appearance settings"
                    />
                    <AppearanceTabs />
                </div>
        </UserLayout>
    );
}
