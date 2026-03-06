import { Badge } from '@/components/ui/badge';

type WorkflowStatus = 'active' | 'completed' | 'cancelled';

const statusConfig: Record<WorkflowStatus, { label: string; className: string }> = {
    active: { label: 'Aktif', className: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' },
    completed: { label: 'Selesai', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
    cancelled: { label: 'Dibatalkan', className: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' },
};

export default function WorkflowStatusBadge({ status }: { status: WorkflowStatus }) {
    const config = statusConfig[status];
    return <Badge className={config.className}>{config.label}</Badge>;
}
