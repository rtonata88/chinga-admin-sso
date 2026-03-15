import { login } from '@/routes';
import { store } from '@/routes/register';
import { Form, Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';

const COUNTRIES = [
    { code: 'NA', name: 'Namibia' },
    { code: 'ZA', name: 'South Africa' },
    { code: 'BW', name: 'Botswana' },
    { code: 'ZW', name: 'Zimbabwe' },
    { code: 'ZM', name: 'Zambia' },
    { code: 'AO', name: 'Angola' },
    { code: 'MZ', name: 'Mozambique' },
    { code: 'LS', name: 'Lesotho' },
    { code: 'SZ', name: 'Eswatini' },
    { code: 'US', name: 'United States' },
    { code: 'GB', name: 'United Kingdom' },
    { code: 'DE', name: 'Germany' },
    { code: 'FR', name: 'France' },
    { code: 'AU', name: 'Australia' },
    { code: 'CA', name: 'Canada' },
];

export default function Register() {
    const [termsAccepted, setTermsAccepted] = useState(false);
    const [countryCode, setCountryCode] = useState('');

    return (
        <AuthLayout
            title="Create an account"
            description="Enter your details below to create your account"
        >
            <Head title="Register" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors, data, setData }) => (
                    <>
                        <div className="grid gap-5">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Full Name</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Your full name"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="username">
                                    Username{' '}
                                    <span className="text-muted-foreground text-xs">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="username"
                                    type="text"
                                    tabIndex={2}
                                    autoComplete="username"
                                    name="username"
                                    placeholder="Choose a username"
                                />
                                <InputError message={errors.username} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email Address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={3}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="phone">
                                    Phone Number{' '}
                                    <span className="text-muted-foreground text-xs">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="phone"
                                    type="tel"
                                    tabIndex={4}
                                    autoComplete="tel"
                                    name="phone"
                                    placeholder="+264 81 123 4567"
                                />
                                <InputError message={errors.phone} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="date_of_birth">
                                    Date of Birth
                                </Label>
                                <Input
                                    id="date_of_birth"
                                    type="date"
                                    required
                                    tabIndex={5}
                                    autoComplete="bday"
                                    name="date_of_birth"
                                    max={
                                        new Date(
                                            Date.now() -
                                                18 * 365.25 * 24 * 60 * 60 * 1000
                                        )
                                            .toISOString()
                                            .split('T')[0]
                                    }
                                />
                                <p className="text-muted-foreground text-xs">
                                    You must be at least 18 years old to
                                    register.
                                </p>
                                <InputError message={errors.date_of_birth} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="country_code">Country</Label>
                                <Select
                                    name="country_code"
                                    value={countryCode}
                                    onValueChange={(value) => {
                                        setCountryCode(value);
                                        setData('country_code', value);
                                    }}
                                >
                                    <SelectTrigger tabIndex={6}>
                                        <SelectValue placeholder="Select your country" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {COUNTRIES.map((country) => (
                                            <SelectItem
                                                key={country.code}
                                                value={country.code}
                                            >
                                                {country.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <input
                                    type="hidden"
                                    name="country_code"
                                    value={countryCode}
                                />
                                <InputError message={errors.country_code} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    required
                                    tabIndex={7}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Create a password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm Password
                                </Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    required
                                    tabIndex={8}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Confirm your password"
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="terms_accepted"
                                    name="terms_accepted"
                                    tabIndex={9}
                                    checked={termsAccepted}
                                    onCheckedChange={(checked) => {
                                        setTermsAccepted(checked === true);
                                        setData(
                                            'terms_accepted',
                                            checked === true
                                        );
                                    }}
                                />
                                <input
                                    type="hidden"
                                    name="terms_accepted"
                                    value={termsAccepted ? '1' : '0'}
                                />
                                <div className="grid gap-1">
                                    <Label
                                        htmlFor="terms_accepted"
                                        className="text-sm font-normal leading-snug"
                                    >
                                        I agree to the{' '}
                                        <TextLink
                                            href="/terms"
                                            className="underline"
                                        >
                                            Terms of Service
                                        </TextLink>{' '}
                                        and{' '}
                                        <TextLink
                                            href="/privacy"
                                            className="underline"
                                        >
                                            Privacy Policy
                                        </TextLink>
                                    </Label>
                                    <InputError
                                        message={errors.terms_accepted}
                                    />
                                </div>
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={10}
                                disabled={!termsAccepted}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                Create account
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            Already have an account?{' '}
                            <TextLink href={login()} tabIndex={11}>
                                Log in
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
