import { useState, useEffect, useCallback } from 'react';
import type { FormConfig, FieldsetConfig } from '@/types/acumatica';

interface UseFormConfigOptions {
    formName: string;
    initialConfig?: FormConfig;
}

interface UseFormConfigReturn {
    config: FormConfig | null;
    loading: boolean;
    configMode: boolean;
    setConfigMode: (v: boolean) => void;
    saveConfig: (fieldsets: FieldsetConfig[], scope: 'system' | 'user') => Promise<void>;
    resetConfig: () => Promise<void>;
    refresh: () => Promise<void>;
}

export function useFormConfig({ formName, initialConfig }: UseFormConfigOptions): UseFormConfigReturn {
    const [config, setConfig] = useState<FormConfig | null>(initialConfig || null);
    const [loading, setLoading] = useState(!initialConfig);
    const [configMode, setConfigMode] = useState(false);

    const fetchConfig = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetch(`/api/form-config/${formName}`);
            const data = await res.json();
            setConfig(data);
        } catch (err) {
            console.error('Failed to load form config:', err);
        } finally {
            setLoading(false);
        }
    }, [formName]);

    useEffect(() => {
        if (!initialConfig) fetchConfig();
    }, [fetchConfig, initialConfig]);

    const saveConfig = useCallback(
        async (fieldsets: FieldsetConfig[], scope: 'system' | 'user') => {
            const endpoint = `/api/form-config/${formName}/${scope}`;
            const res = await fetch(endpoint, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ fieldsets }),
            });
            if (!res.ok) throw new Error('Save failed');
            await fetchConfig();
            setConfigMode(false);
        },
        [formName, fetchConfig]
    );

    const resetConfig = useCallback(async () => {
        const res = await fetch(`/api/form-config/${formName}/user`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
            },
        });
        if (!res.ok) throw new Error('Reset failed');
        await fetchConfig();
    }, [formName, fetchConfig]);

    return {
        config,
        loading,
        configMode,
        setConfigMode,
        saveConfig,
        resetConfig,
        refresh: fetchConfig,
    };
}
