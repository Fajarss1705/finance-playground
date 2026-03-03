import { Link, usePage } from '@inertiajs/react';
import { Bell, BookOpen, Building2, FileText, Folder, FolderOpen, Layers, LayoutGrid, Settings, Shield, UserCog, Users } from 'lucide-react';
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
import { index as adminIndex } from '@/routes/admin';
import { index as adminFilesIndex } from '@/routes/admin/files';
import { index as adminNotificationsIndex } from '@/routes/admin/notifications';
import { index as adminOrganizationsIndex } from '@/routes/admin/organizations';
import { index as adminRolesIndex } from '@/routes/admin/roles';
import { index as adminTeamsIndex } from '@/routes/admin/teams';
import { index as adminUsersIndex } from '@/routes/admin/users';
import { index as adminWorkspacesIndex } from '@/routes/admin/workspaces';
import { files as personalFiles, index as personalIndex, notifications as personalNotifications } from '@/routes/personal';
import { index as teamIndex } from '@/routes/team';
import { index as teamFilesIndex } from '@/routes/team/files';
import type { Auth, NavItem } from '@/types';
import AppLogo from './app-logo';

const personalNavItems: NavItem[] = [
    {
        title: 'Dashboard Personal',
        href: personalIndex(),
        icon: LayoutGrid,
    },
    {
        title: 'File Saya',
        href: personalFiles(),
        icon: FileText,
        permission: 'personal.files',
    },
    {
        title: 'Notifikasi Saya',
        href: personalNotifications(),
        icon: Bell,
        permission: 'personal.notifications',
    },
];

const teamNavItems: NavItem[] = [
    {
        title: 'Dashboard Tim',
        href: teamIndex(),
        icon: LayoutGrid,
        permission: 'team.index',
    },
    {
        title: 'File Tim',
        href: teamFilesIndex(),
        icon: FolderOpen,
        permission: 'team.files.index',
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
    {
        title: 'Role',
        href: adminRolesIndex(),
        icon: Shield,
        permission: 'admin.roles.index',
    },
    {
        title: 'Pengguna',
        href: adminUsersIndex(),
        icon: UserCog,
        permission: 'admin.users.index',
    },
    {
        title: 'Semua File',
        href: adminFilesIndex(),
        icon: Folder,
        permission: 'admin.files.index',
    },
    {
        title: 'Semua Notifikasi',
        href: adminNotificationsIndex(),
        icon: Bell,
        permission: 'admin.notifications.index',
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
    const { auth } = usePage<{ auth: Auth }>().props;
    const teamLabel = auth.activeRole?.team?.name ? `Tim: ${auth.activeRole.team.name}` : 'Tim';

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={personalIndex()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={personalNavItems} label="Personal" iconClassName="text-blue-600 dark:text-blue-400" />
                <NavMain items={teamNavItems} label={teamLabel} iconClassName="text-emerald-600 dark:text-emerald-400" />
                <NavMain items={adminNavItems} label="Manajemen" iconClassName="text-amber-600 dark:text-amber-400" />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
