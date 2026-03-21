import { Head } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import RoleTaskSection from '@/components/dashboard/role-task-section';
import TaskCard from '@/components/dashboard/task-card';
import type {TaskItem} from '@/components/dashboard/task-card';
import Heading from '@/components/heading';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import { index as personalIndex } from '@/routes/personal';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Personal', href: personalIndex() }];

type OtherRole = {
    id: number;
    name: string;
    teamName: string;
    workspaceId: number;
    tasks: TaskItem[];
};

type PersonalDashboardProps = {
    activeRole: {
        id: number;
        name: string;
        teamName: string;
    } | null;
    activeRoleTasks: TaskItem[];
    otherRoles: OtherRole[];
};

export default function Dashboard({ activeRole, activeRoleTasks, otherRoles }: PersonalDashboardProps) {
    const [activeOpen, setActiveOpen] = useState(true);
    const [otherOpen, setOtherOpen] = useState(false);

    if (!activeRole) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Personal" />
                <div className="space-y-6 p-6">
                    <Heading title="Personal" description="Dashboard personal" />
                    <div className="rounded-lg border bg-card p-8 text-center shadow-sm">
                        <p className="text-sm text-muted-foreground">Anda belum memiliki role. Hubungi administrator.</p>
                    </div>
                </div>
            </AppLayout>
        );
    }

    const rolesWithTasks = otherRoles.filter((r) => r.tasks.length > 0).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Personal" />
            <div className="space-y-4 p-6">
                <Heading title="Personal" description="Dashboard personal" />

                {/* Section 1: Active Role Tasks */}
                <Collapsible open={activeOpen} onOpenChange={setActiveOpen}>
                    <CollapsibleTrigger className="flex w-full items-center justify-between rounded-lg border bg-card px-4 py-3 text-left shadow-sm hover:bg-muted/50">
                        <div className="flex items-center gap-2">
                            {activeOpen ? (
                                <ChevronDown className="h-4 w-4 text-muted-foreground" />
                            ) : (
                                <ChevronRight className="h-4 w-4 text-muted-foreground" />
                            )}
                            <span className="text-sm font-medium">
                                Tugas Anda &mdash; {activeRole.name} &middot; {activeRole.teamName}
                            </span>
                        </div>
                        <span className="text-xs text-muted-foreground">
                            {activeRoleTasks.length} tugas
                        </span>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <div className="mt-1 text-xs text-muted-foreground px-4 pb-1">Role aktif saat ini</div>
                        <div className="mt-1 space-y-2 pl-2">
                            {activeRoleTasks.length === 0 ? (
                                <p className="py-3 text-center text-sm text-muted-foreground">
                                    Tidak ada tugas untuk role ini.
                                </p>
                            ) : (
                                activeRoleTasks.map((task, i) => (
                                    <TaskCard key={`active-${task.stepCode}-${task.url}-${i}`} task={task} />
                                ))
                            )}
                        </div>
                    </CollapsibleContent>
                </Collapsible>

                {/* Section 2: Other Roles (hidden if user has only 1 role) */}
                {otherRoles.length > 0 && (
                    <Collapsible open={otherOpen} onOpenChange={setOtherOpen}>
                        <CollapsibleTrigger className="flex w-full items-center justify-between rounded-lg border bg-card px-4 py-3 text-left shadow-sm hover:bg-muted/50">
                            <div className="flex items-center gap-2">
                                {otherOpen ? (
                                    <ChevronDown className="h-4 w-4 text-muted-foreground" />
                                ) : (
                                    <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                )}
                                <span className="text-sm font-medium">Role Lain Anda</span>
                            </div>
                            <span className="text-xs text-muted-foreground">
                                {rolesWithTasks > 0
                                    ? `${rolesWithTasks} role dengan tugas`
                                    : `${otherRoles.length} role`}
                            </span>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div className="mt-2 space-y-3 pl-2">
                                {otherRoles.map((role) => (
                                    <RoleTaskSection
                                        key={role.id}
                                        title={`${role.name} \u00b7 ${role.teamName}`}
                                        badge={`(${role.tasks.length})`}
                                        tasks={role.tasks}
                                        switchRole={{
                                            roleId: role.id,
                                            workspaceId: role.workspaceId,
                                        }}
                                    />
                                ))}
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                )}
            </div>
        </AppLayout>
    );
}
