import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import TaskCard from '@/components/dashboard/task-card';
import type {TaskItem} from '@/components/dashboard/task-card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';

type RoleTaskSectionProps = {
    title: string;
    badge: string;
    tasks: TaskItem[];
    defaultOpen?: boolean;
    switchRole?: {
        roleId: number;
        workspaceId: number;
    };
    emptyText?: string;
};

export default function RoleTaskSection({
    title,
    badge,
    tasks,
    defaultOpen = false,
    switchRole,
    emptyText = 'Tidak ada tugas.',
}: RoleTaskSectionProps) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <CollapsibleTrigger className="flex w-full items-center justify-between rounded-lg border bg-card px-4 py-3 text-left shadow-sm hover:bg-muted/50">
                <div className="flex items-center gap-2">
                    {open ? <ChevronDown className="h-4 w-4 text-muted-foreground" /> : <ChevronRight className="h-4 w-4 text-muted-foreground" />}
                    <span className="text-sm font-medium">{title}</span>
                </div>
                <span className="text-xs text-muted-foreground">{badge}</span>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className="mt-2 space-y-2 pl-2">
                    {tasks.length === 0 ? (
                        <p className="py-3 text-center text-sm text-muted-foreground">{emptyText}</p>
                    ) : (
                        tasks.map((task, i) => <TaskCard key={`${task.stepCode}-${task.url}-${i}`} task={task} switchRole={switchRole} />)
                    )}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
