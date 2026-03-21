import { Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type TaskItem = {
    workflowType: 'pp' | 'pk' | 'pabd' | 'prbl';
    workflowLabel: string;
    stepCode: string;
    stepName: string;
    statusHint: string;
    url: string;
};

type TaskCardProps = {
    task: TaskItem;
    switchRole?: {
        roleId: number;
        workspaceId: number;
    };
};

const statusVariant: Record<string, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    Draft: 'secondary',
    Dikembalikan: 'destructive',
    'Menunggu review': 'outline',
    'Menunggu diisi': 'outline',
};

export default function TaskCard({ task, switchRole }: TaskCardProps) {
    const handleSwitchAndWork = () => {
        if (!switchRole) return;
        router.post('/role-selector', {
            role_id: switchRole.roleId,
            workspace_id: switchRole.workspaceId,
            redirect_to: task.url,
        });
    };

    return (
        <div className="flex items-center justify-between gap-3 rounded-md border px-4 py-3">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="font-mono text-xs font-semibold">{task.stepCode}</span>
                    <span className="text-sm">&mdash; {task.stepName}</span>
                </div>
                <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                    <span className="truncate">{task.workflowLabel}</span>
                    <Badge
                        variant={statusVariant[task.statusHint] ?? 'outline'}
                        className={cn(
                            'text-[10px]',
                            task.statusHint === 'Draft' && 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
                            task.statusHint === 'Dikembalikan' && 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
                        )}
                    >
                        {task.statusHint}
                    </Badge>
                </div>
            </div>

            {switchRole ? (
                <Button variant="outline" size="sm" className="shrink-0 text-xs" onClick={handleSwitchAndWork}>
                    Ganti &amp; Kerjakan &rarr;
                </Button>
            ) : (
                <Button variant="outline" size="sm" className="shrink-0 text-xs" asChild>
                    <Link href={task.url}>Kerjakan &rarr;</Link>
                </Button>
            )}
        </div>
    );
}
