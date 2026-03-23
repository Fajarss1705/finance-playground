import { Link, usePage } from '@inertiajs/react';
import type { ComponentPropsWithoutRef } from 'react';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { toUrl } from '@/lib/utils';
import type { Auth, NavItem } from '@/types';

export function NavFooter({
    items,
    iconClassName,
    className,
    ...props
}: ComponentPropsWithoutRef<typeof SidebarGroup> & {
    items: NavItem[];
    iconClassName?: string;
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const permissions = auth.permissions ?? [];

    const visibleItems = items.filter(
        (item) => !item.permission || permissions.includes(item.permission),
    );

    if (visibleItems.length === 0) {
        return null;
    }

    return (
        <SidebarGroup
            {...props}
            className={`group-data-[collapsible=icon]:p-0 ${className || ''}`}
        >
            <SidebarGroupContent>
                <SidebarMenu>
                    {visibleItems.map((item) => (
                        <SidebarMenuItem key={item.title}>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <SidebarMenuButton
                                        asChild
                                        className="text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
                                        tooltip={{ children: item.title }}
                                    >
                                        <Link href={toUrl(item.href)} prefetch>
                                            {item.icon && (
                                                <item.icon className={item.iconClassName ?? iconClassName} />
                                            )}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </TooltipTrigger>
                                <TooltipContent side="right" align="center">
                                    {item.title}
                                </TooltipContent>
                            </Tooltip>
                        </SidebarMenuItem>
                    ))}
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}
