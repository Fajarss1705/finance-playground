import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { BottomBar } from '@/components/bottom-bar';
import { NotificationBell } from '@/components/notification-bell';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <div className="flex-1 pb-28">
                    {children}
                </div>
                <div className="fixed bottom-20 right-6 z-20">
                    <NotificationBell />
                </div>
                <BottomBar />
            </AppContent>
        </AppShell>
    );
}
