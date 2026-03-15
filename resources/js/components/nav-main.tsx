import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { usePermission } from '@/hooks/use-permission';
import type { NavItem } from '@/types';

export function NavMain({
    items = [],
    label = 'Personal',
    iconClassName,
}: {
    items: NavItem[];
    label?: string;
    iconClassName?: string;
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const { can } = usePermission();

    const visibleItems = items.filter(
        (item) => !item.permission || can(item.permission),
    );

    if (visibleItems.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {visibleItems.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon className={iconClassName} />}
                                <span className={`truncate ${item.emphasis ? 'font-semibold' : ''}`}>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
