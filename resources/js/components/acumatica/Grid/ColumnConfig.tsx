import { useState, useEffect } from 'react';
import { Dialog } from 'primereact/dialog';
import { Checkbox } from 'primereact/checkbox';
import { Button } from 'primereact/button';
import {
    DndContext,
    closestCenter,
    PointerSensor,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core';
import {
    SortableContext,
    verticalListSortingStrategy,
    useSortable,
    arrayMove,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import type { GridColumn } from '@/types/acumatica';

interface Props {
    visible: boolean;
    columns: GridColumn[];
    onHide: () => void;
    onSave: (columns: GridColumn[]) => void;
}

function SortableColumnItem({
    col,
    onToggle,
}: {
    col: GridColumn;
    onToggle: () => void;
}) {
    const { attributes, listeners, setNodeRef, transform, transition } = useSortable({
        id: col.field,
    });
    const style = { transform: CSS.Transform.toString(transform), transition };

    return (
        <div
            ref={setNodeRef}
            style={style}
            className="flex items-center gap-3 p-2 border border-[var(--acu-border)] rounded bg-white"
        >
            <i {...attributes} {...listeners} className="pi pi-bars text-gray-400 cursor-grab" />
            <Checkbox checked={col.visible !== false} onChange={onToggle} />
            <span className="text-sm">{col.header}</span>
        </div>
    );
}

export default function ColumnConfig({ visible, columns, onHide, onSave }: Props) {
    const [local, setLocal] = useState<GridColumn[]>([]);

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 5 } })
    );

    useEffect(() => {
        if (visible) setLocal(structuredClone(columns));
    }, [visible, columns]);

    const handleDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;
        if (!over || active.id === over.id) return;
        setLocal((prev) => {
            const oldIdx = prev.findIndex((c) => c.field === active.id);
            const newIdx = prev.findIndex((c) => c.field === over.id);
            return arrayMove(prev, oldIdx, newIdx);
        });
    };

    const toggleVisibility = (field: string) => {
        setLocal((prev) =>
            prev.map((c) =>
                c.field === field ? { ...c, visible: c.visible === false ? true : false } : c
            )
        );
    };

    return (
        <Dialog
            visible={visible}
            onHide={onHide}
            header="Column Settings"
            style={{ width: '400px' }}
            footer={
                <div className="flex justify-end gap-2">
                    <Button label="Cancel" severity="secondary" outlined size="small" onClick={onHide} />
                    <Button
                        label="Apply"
                        size="small"
                        onClick={() => { onSave(local); onHide(); }}
                    />
                </div>
            }
        >
            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                <SortableContext items={local.map((c) => c.field)} strategy={verticalListSortingStrategy}>
                    <div className="space-y-2">
                        {local.map((col) => (
                            <SortableColumnItem
                                key={col.field}
                                col={col}
                                onToggle={() => toggleVisibility(col.field)}
                            />
                        ))}
                    </div>
                </SortableContext>
            </DndContext>
        </Dialog>
    );
}
