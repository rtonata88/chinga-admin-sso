import { useState, useCallback } from 'react';

interface UseFieldsetReturn {
    isCollapsed: (id: string) => boolean;
    toggle: (id: string) => void;
    collapseAll: () => void;
    expandAll: () => void;
}

export function useFieldset(
    fieldsetIds: string[],
    initialCollapsed: Record<string, boolean> = {}
): UseFieldsetReturn {
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>(initialCollapsed);

    const isCollapsed = useCallback(
        (id: string) => collapsed[id] ?? false,
        [collapsed]
    );

    const toggle = useCallback(
        (id: string) =>
            setCollapsed((prev) => ({ ...prev, [id]: !prev[id] })),
        []
    );

    const collapseAll = useCallback(
        () =>
            setCollapsed(
                Object.fromEntries(fieldsetIds.map((id) => [id, true]))
            ),
        [fieldsetIds]
    );

    const expandAll = useCallback(
        () =>
            setCollapsed(
                Object.fromEntries(fieldsetIds.map((id) => [id, false]))
            ),
        [fieldsetIds]
    );

    return { isCollapsed, toggle, collapseAll, expandAll };
}
