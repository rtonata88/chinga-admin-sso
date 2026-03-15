import type { StatusVariant } from '@/types/acumatica';

const VARIANTS: Record<StatusVariant, string> = {
    active: 'acu-status--active',
    inactive: 'acu-status--inactive',
    suspended: 'acu-status--suspended',
    pending: 'acu-status--pending',
    completed: 'acu-status--completed',
    error: 'acu-status--error',
};

interface Props {
    status: StatusVariant;
    label?: string;
}

export default function StatusBadge({ status, label }: Props) {
    return (
        <span className={`acu-status ${VARIANTS[status] || VARIANTS.inactive}`}>
            <span className="w-1.5 h-1.5 rounded-full bg-current" />
            {label || status}
        </span>
    );
}
