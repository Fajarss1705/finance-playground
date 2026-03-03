import { Link } from '@inertiajs/react';
import { BookOpen, Building2, Folder, Layers, LayoutGrid, Settings, Users } from 'lucide-react';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as adminIndex } from '@/routes/admin';
import { index as adminOrganizationsIndex } from '@/routes/admin/organizations';
import { index as adminTeamsIndex } from '@/routes/admin/teams';
import { index as adminWorkspacesIndex } from '@/routes/admin/workspaces';
import type { NavItem } from '@/types';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Dashboard Manajemen',
        href: adminIndex(),
        icon: Settings,
        permission: 'admin.index',
    },
    {
        title: 'Workspace',
        href: adminWorkspacesIndex(),
        icon: Layers,
        permission: 'admin.workspaces.index',
    },
    {
        title: 'Organisasi',
        href: adminOrganizationsIndex(),
        icon: Building2,
        permission: 'admin.organizations.index',
    },
    {
        title: 'Tim',
        href: adminTeamsIndex(),
        icon: Users,
        permission: 'admin.teams.index',
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <NavMain items={adminNavItems} label="Manajemen" />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
