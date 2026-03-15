import { useState, useMemo, useCallback, type ReactNode } from 'react';
import { DataTable, type DataTablePageEvent, type DataTableSortEvent } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { Button } from 'primereact/button';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import FilterPresets from './FilterPresets';
import ColumnConfig from './ColumnConfig';
import type { GridColumn, SavedFilter, StatusVariant } from '@/types/acumatica';

interface Props {
    data: any[];
    columns: GridColumn[];
    filterableType: string;
    totalRecords?: number;
    loading?: boolean;
    paginator?: boolean;
    rows?: number;
    rowsPerPageOptions?: number[];
    onPage?: (event: DataTablePageEvent) => void;
    onSort?: (event: DataTableSortEvent) => void;
    sortField?: string;
    sortOrder?: 1 | -1;
    selectionMode?: 'single' | 'multiple';
    selection?: any;
    onSelectionChange?: (value: any) => void;
    actions?: (rowData: any) => ReactNode;
    onAdd?: () => void;
    addLabel?: string;
    rowClassName?: (data: any) => string;
    header?: ReactNode;
}

export default function SmartGrid({
    data,
    columns: initialColumns,
    filterableType,
    totalRecords,
    loading = false,
    paginator = true,
    rows = 20,
    rowsPerPageOptions = [10, 20, 50, 100],
    onPage,
    onSort,
    sortField,
    sortOrder,
    selectionMode,
    selection,
    onSelectionChange,
    actions,
    onAdd,
    addLabel = 'Add New',
    rowClassName,
    header,
}: Props) {
    const [globalFilter, setGlobalFilter] = useState('');
    const [columns, setColumns] = useState(initialColumns);
    const [columnConfigVisible, setColumnConfigVisible] = useState(false);
    const [activeFilter, setActiveFilter] = useState<SavedFilter | null>(null);

    const visibleColumns = useMemo(
        () => columns.filter((c) => c.visible !== false),
        [columns]
    );

    const renderColumn = useCallback(
        (col: GridColumn) => {
            if (col.type === 'status') {
                return (
                    <Column
                        key={col.field}
                        field={col.field}
                        header={col.header}
                        sortable={col.sortable}
                        style={{ width: col.width }}
                        frozen={col.frozen}
                        body={(rowData) => (
                            <StatusBadge status={rowData[col.field] as StatusVariant} />
                        )}
                    />
                );
            }
            if (col.type === 'date') {
                return (
                    <Column
                        key={col.field}
                        field={col.field}
                        header={col.header}
                        sortable={col.sortable}
                        style={{ width: col.width }}
                        frozen={col.frozen}
                        body={(rowData) => {
                            const val = rowData[col.field];
                            return val ? new Date(val).toLocaleDateString() : '\u2014';
                        }}
                    />
                );
            }
            if (col.type === 'currency') {
                return (
                    <Column
                        key={col.field}
                        field={col.field}
                        header={col.header}
                        sortable={col.sortable}
                        style={{ width: col.width }}
                        frozen={col.frozen}
                        body={(rowData) => {
                            const val = rowData[col.field];
                            return val != null
                                ? `NAD ${Number(val).toLocaleString()}`
                                : '\u2014';
                        }}
                    />
                );
            }
            return (
                <Column
                    key={col.field}
                    field={col.field}
                    header={col.header}
                    sortable={col.sortable}
                    style={{ width: col.width }}
                    frozen={col.frozen}
                />
            );
        },
        []
    );

    const toolbarLeft = (
        <div className="flex items-center gap-3">
            <span className="p-input-icon-left">
                <i className="pi pi-search" />
                <InputText
                    value={globalFilter}
                    onChange={(e) => setGlobalFilter(e.target.value)}
                    placeholder="Quick search..."
                    className="w-64"
                />
            </span>
        </div>
    );

    const toolbarRight = (
        <div className="flex items-center gap-2">
            <Button
                icon="pi pi-cog"
                outlined
                rounded
                severity="secondary"
                size="small"
                tooltip="Column settings"
                onClick={() => setColumnConfigVisible(true)}
            />
            <Button
                icon="pi pi-download"
                outlined
                rounded
                severity="secondary"
                size="small"
                tooltip="Export"
            />
            {onAdd && (
                <Button
                    label={addLabel}
                    icon="pi pi-plus"
                    size="small"
                    onClick={onAdd}
                />
            )}
        </div>
    );

    return (
        <div className="acu-grid space-y-3">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                {toolbarLeft}
                {toolbarRight}
            </div>

            <FilterPresets
                filterableType={filterableType}
                activeFilter={activeFilter}
                onApply={setActiveFilter}
            />

            {header}

            <DataTable
                value={data}
                loading={loading}
                globalFilter={globalFilter}
                paginator={paginator}
                rows={rows}
                rowsPerPageOptions={rowsPerPageOptions}
                totalRecords={totalRecords}
                lazy={!!onPage}
                onPage={onPage}
                onSort={onSort}
                sortField={sortField}
                sortOrder={sortOrder}
                selectionMode={selectionMode}
                selection={selection}
                onSelectionChange={(e) => onSelectionChange?.(e.value)}
                rowClassName={rowClassName}
                emptyMessage="No records found."
                showGridlines={false}
                stripedRows={false}
                removableSort
                size="small"
            >
                {selectionMode && <Column selectionMode={selectionMode === 'multiple' ? 'multiple' : 'single'} headerStyle={{ width: '3rem' }} />}

                {visibleColumns.map(renderColumn)}

                {actions && (
                    <Column
                        header="Actions"
                        frozen
                        alignFrozen="right"
                        style={{ width: '120px' }}
                        body={actions}
                    />
                )}
            </DataTable>

            <ColumnConfig
                visible={columnConfigVisible}
                columns={columns}
                onHide={() => setColumnConfigVisible(false)}
                onSave={setColumns}
            />
        </div>
    );
}
