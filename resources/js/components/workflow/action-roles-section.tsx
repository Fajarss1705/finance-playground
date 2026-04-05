import { ChevronRight, Shield } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type ActionRoleEntry = {
    name: string;
    users: string[];
};

export type ActionRole = {
    action: string;
    label: string;
    roles: ActionRoleEntry[];
    highlight?: boolean;
};

type ActionRolesSectionProps = {
    items: ActionRole[];
    activeRoleName?: string | null;
};

export default function ActionRolesSection({ items, activeRoleName }: ActionRolesSectionProps) {
    const [open, setOpen] = useState(false);

    if (items.length === 0) return null;

    const hasAnyAccess = activeRoleName
        ? items.some((item) => item.roles.some((role) => role.name === activeRoleName))
        : false;

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <CollapsibleTrigger className="flex w-full items-center gap-2 rounded-md border px-4 py-2.5 text-sm text-muted-foreground hover:bg-muted/50 transition-colors">
                <ChevronRight className={`h-4 w-4 shrink-0 transition-transform ${open ? 'rotate-90' : ''}`} />
                <Shield className="h-4 w-4 shrink-0" />
                <span>Hak Akses Step Ini</span>
                {activeRoleName && !hasAnyAccess && (
                    <span className="ml-auto text-xs italic text-amber-600 dark:text-amber-400">Anda tidak punya akses di step ini</span>
                )}
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className="mt-2 rounded-md border">
                    {activeRoleName && !hasAnyAccess && (
                        <div className="border-b bg-amber-50/50 px-4 py-2 text-xs text-amber-700 dark:bg-amber-950/20 dark:text-amber-300">
                            Role aktif Anda (<strong>{activeRoleName}</strong>) tidak memiliki akses di step ini.
                        </div>
                    )}
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-2 text-left font-medium w-48">Aksi</th>
                                <th className="px-4 py-2 text-left font-medium">Role yang Diizinkan</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((item) => (
                                <tr key={item.action} className={`border-b last:border-0 ${item.highlight ? 'bg-amber-50/50 dark:bg-amber-950/20' : ''}`}>
                                    <td className="px-4 py-2">
                                        {item.highlight ? (
                                            <Badge className="bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                                {item.label}
                                            </Badge>
                                        ) : (
                                            <span className="text-muted-foreground">{item.label}</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">
                                        <div className="flex flex-wrap gap-1.5">
                                            {item.roles.length > 0 ? (
                                                item.roles.map((role) => (
                                                    <Tooltip key={role.name}>
                                                        <TooltipTrigger asChild>
                                                            <span>
                                                                <Badge
                                                                    variant="secondary"
                                                                    className={cn(
                                                                        'cursor-default text-xs',
                                                                        activeRoleName && role.name === activeRoleName
                                                                            ? 'bg-blue-100 text-blue-800 ring-1 ring-blue-300 dark:bg-blue-900 dark:text-blue-200 dark:ring-blue-700'
                                                                            : '',
                                                                    )}
                                                                >
                                                                    {role.name}
                                                                </Badge>
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent side="top">
                                                            {role.users.length > 0 ? (
                                                                <div className="space-y-0.5">
                                                                    {role.users.map((user) => (
                                                                        <div key={user}>{user}</div>
                                                                    ))}
                                                                </div>
                                                            ) : (
                                                                <span className="italic">Tidak ada user</span>
                                                            )}
                                                        </TooltipContent>
                                                    </Tooltip>
                                                ))
                                            ) : (
                                                <span className="text-xs text-muted-foreground">Tidak ada role</span>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
