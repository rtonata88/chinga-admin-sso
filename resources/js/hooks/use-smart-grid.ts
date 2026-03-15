import { useState, useCallback, useEffect } from 'react';
import { router } from '@inertiajs/react';
import type { GridColumn, SavedFilter } from '@/types/acumatica';

interface UseSmartGridOptions {
    fetchUrl: string;
    columns: GridColumn[];
    filterableType: string;
    defaultRows?: number;
    serverSide?: boolean;
}

interface UseSmartGridReturn {
    columns: GridColumn[];
    setColumns: (cols: GridColumn[]) => void;
    page: number;
    rows: number;
    sortField: string | undefined;
    sortOrder: 1 | -1;
    globalFilter: string;
    setGlobalFilter: (v: string) => void;
    activeFilter: SavedFilter | null;
    setActiveFilter: (f: SavedFilter | null) => void;
    onPage: (event: { first: number; rows: number }) => void;
    onSort: (event: { sortField: string; sortOrder: 1 | -1 }) => void;
    reload: () => void;
}

export function useSmartGrid({
    fetchUrl,
    columns: initialColumns,
    filterableType,
    defaultRows = 20,
    serverSide = true,
}: UseSmartGridOptions): UseSmartGridReturn {
    const [columns, setColumns] = useState(initialColumns);
    const [page, setPage] = useState(0);
    const [rows, setRows] = useState(defaultRows);
    const [sortField, setSortField] = useState<string | undefined>(undefined);
    const [sortOrder, setSortOrder] = useState<1 | -1>(1);
    const [globalFilter, setGlobalFilter] = useState('');
    const [activeFilter, setActiveFilter] = useState<SavedFilter | null>(null);

    const buildParams = useCallback(() => {
        const params: Record<string, any> = {
            page: Math.floor(page / rows) + 1,
            per_page: rows,
        };
        if (sortField) {
            params.sort_by = sortField;
            params.sort_dir = sortOrder === 1 ? 'asc' : 'desc';
        }
        if (globalFilter) params.search = globalFilter;
        if (activeFilter?.criteria) params.filters = JSON.stringify(activeFilter.criteria);
        return params;
    }, [page, rows, sortField, sortOrder, globalFilter, activeFilter]);

    const reload = useCallback(() => {
        if (!serverSide) return;
        router.get(fetchUrl, buildParams(), {
            preserveState: true,
            preserveScroll: true,
            only: ['data'],
        });
    }, [fetchUrl, buildParams, serverSide]);

    useEffect(() => {
        if (serverSide) reload();
    }, [page, rows, sortField, sortOrder, activeFilter]);

    useEffect(() => {
        if (!serverSide || !globalFilter) return;
        const timer = setTimeout(reload, 400);
        return () => clearTimeout(timer);
    }, [globalFilter]);

    const onPage = useCallback((event: { first: number; rows: number }) => {
        setPage(event.first);
        setRows(event.rows);
    }, []);

    const onSort = useCallback((event: { sortField: string; sortOrder: 1 | -1 }) => {
        setSortField(event.sortField);
        setSortOrder(event.sortOrder);
    }, []);

    return {
        columns,
        setColumns,
        page,
        rows,
        sortField,
        sortOrder,
        globalFilter,
        setGlobalFilter,
        activeFilter,
        setActiveFilter,
        onPage,
        onSort,
        reload,
    };
}
