import { useState, useEffect } from 'react';
import { Button } from 'primereact/button';
import type { SavedFilter } from '@/types/acumatica';

interface Props {
    filterableType: string;
    activeFilter: SavedFilter | null;
    onApply: (filter: SavedFilter | null) => void;
}

export default function FilterPresets({ filterableType, activeFilter, onApply }: Props) {
    const [filters, setFilters] = useState<SavedFilter[]>([]);

    useEffect(() => {
        fetch(`/api/filters/${filterableType}`)
            .then((r) => r.json())
            .then(setFilters)
            .catch(() => {});
    }, [filterableType]);

    if (filters.length === 0) return null;

    return (
        <div className="flex items-center gap-2 flex-wrap">
            <span className="text-xs text-[var(--acu-text-muted)] uppercase font-semibold mr-1">Filters:</span>
            <Button
                label="All"
                size="small"
                rounded
                outlined={activeFilter !== null}
                severity={activeFilter === null ? undefined : 'secondary'}
                onClick={() => onApply(null)}
                className="text-xs"
            />
            {filters.map((f) => (
                <Button
                    key={f.id}
                    label={f.name}
                    size="small"
                    rounded
                    outlined={activeFilter?.id !== f.id}
                    severity={activeFilter?.id === f.id ? undefined : 'secondary'}
                    icon={f.isFavorite ? 'pi pi-star-fill' : undefined}
                    onClick={() => onApply(f)}
                    className="text-xs"
                />
            ))}
        </div>
    );
}
