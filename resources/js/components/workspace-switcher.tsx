import { router, usePage } from '@inertiajs/react';
import { ChevronsUpDown, Check } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Auth } from '@/types';
import { store } from '@/actions/App/Http/Controllers/SwitcherController';

export function WorkspaceSwitcher() {
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
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button className="flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent transition-colors">
                    <div className="min-w-0">
                        <div className="truncate font-medium">{activeWorkspace.name}</div>
                        <div className="truncate text-xs text-muted-foreground">
                            {activeRole.name} &middot; {activeRole.team.name}
                        </div>
                    </div>
                    <ChevronsUpDown className="ml-1 size-4 shrink-0 opacity-50" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className="max-h-[calc(100vh-4rem)] w-64 overflow-y-auto" align="start">
                {workspaces.map(({ workspace, roles }, idx) => (
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
                                <DropdownMenuLabel className="text-xs font-normal text-muted-foreground/70 px-2 py-1">
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
    );
}
