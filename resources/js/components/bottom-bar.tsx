import { router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Shield } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { Auth } from '@/types';
import { store } from '@/actions/App/Http/Controllers/SwitcherController';

export function BottomBar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { activeRole, activeWorkspace, workspaces } = auth;

    if (!activeRole || !activeWorkspace) {
        return null;
    }

    const handleSwitch = (roleId: number, workspaceId: number) => {
        router.post(store.url(), {
            role_id: roleId,
            workspace_id: workspaceId,
        });
    };

    return (
        <div className="fixed bottom-0 right-0 left-(--sidebar-width) z-10 flex h-18 items-center border-t border-blue-300/30 bg-blue-50/70 px-4 shadow-[0_-2px_12px_rgba(59,130,246,0.08)] backdrop-blur-xl transition-[left,height] duration-200 ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:left-[calc(var(--sidebar-width-icon)+(--spacing(4)))] max-md:left-0 dark:border-blue-500/20 dark:bg-blue-950/50 dark:shadow-[0_-2px_12px_rgba(59,130,246,0.12)]">
            <TooltipProvider delayDuration={300}>
                <Tooltip>
                    <DropdownMenu>
                        <TooltipTrigger asChild>
                            <DropdownMenuTrigger asChild>
                                <button className="flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors hover:bg-accent">
                                    <Shield className="size-4 shrink-0 text-blue-600 dark:text-blue-400" />
                                    <Badge className="bg-blue-600 text-white dark:bg-blue-500">{activeRole.name}</Badge>
                                    <span className="text-muted-foreground">&middot;</span>
                                    <span className="truncate text-muted-foreground">{activeRole.team.name}</span>
                                    <span className="text-muted-foreground">&middot;</span>
                                    <span className="truncate text-muted-foreground">{activeWorkspace.name}</span>
                                    <ChevronsUpDown className="ml-1 size-4 shrink-0 opacity-50" />
                                </button>
                            </DropdownMenuTrigger>
                        </TooltipTrigger>
                        <TooltipContent side="top" align="start" className="text-xs">
                            <div>Role: {activeRole.name}</div>
                            <div>Tim: {activeRole.team.name}</div>
                            <div>Workspace: {activeWorkspace.name}</div>
                        </TooltipContent>
                        <DropdownMenuContent className="max-h-[calc(100vh-4rem)] w-64 overflow-y-auto" align="start" side="top">
                            {workspaces.map(({ workspace, roles }) => (
                                <div key={workspace.id} className="mx-1.5 my-1 rounded-lg border bg-muted/30 p-1.5 dark:bg-muted/20">
                                    <DropdownMenuLabel className="text-xs text-muted-foreground">
                                        {workspace.name}
                                    </DropdownMenuLabel>
                                    {Object.entries(
                                        roles.reduce<Record<string, typeof roles>>((groups, role) => {
                                            const key = role.team.name;
                                            (groups[key] ??= []).push(role);
                                            return groups;
                                        }, {}),
                                    ).map(([teamName, teamRoles]) => (
                                        <DropdownMenuGroup key={`${workspace.id}-${teamName}`} className="mt-1 rounded-md border bg-background/60 p-1 dark:bg-background/40">
                                            <DropdownMenuLabel className="px-2 py-1 text-xs font-normal text-muted-foreground/70">
                                                {teamName}
                                            </DropdownMenuLabel>
                                            {teamRoles.map((role) => {
                                                const isActive =
                                                    activeRole.id === role.id &&
                                                    activeWorkspace.id === workspace.id;

                                                return (
                                                    <DropdownMenuItem
                                                        key={`${workspace.id}-${role.id}`}
                                                        onClick={() => handleSwitch(role.id, workspace.id)}
                                                        className={`flex items-center justify-between ${isActive ? 'bg-accent font-semibold' : ''}`}
                                                    >
                                                        <div className="font-medium">{role.name}</div>
                                                        {isActive && <Check className="size-4 shrink-0" />}
                                                    </DropdownMenuItem>
                                                );
                                            })}
                                        </DropdownMenuGroup>
                                    ))}
                                </div>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </Tooltip>
            </TooltipProvider>
        </div>
    );
}
