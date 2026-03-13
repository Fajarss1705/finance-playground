import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Toaster } from 'sonner';
import FlashMessages from '@/components/flash-messages';
import { SidebarProvider } from '@/components/ui/sidebar';

type Props = {
    children: ReactNode;
    variant?: 'header' | 'sidebar';
};

export function AppShell({ children, variant = 'header' }: Props) {
    const isOpen = usePage().props.sidebarOpen;

    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">
                {children}
                <Toaster position="top-right" richColors closeButton />
                <FlashMessages />
            </div>
        );
    }

    return (
        <SidebarProvider defaultOpen={isOpen}>
            {children}
            <Toaster position="top-right" richColors closeButton />
            <FlashMessages />
        </SidebarProvider>
    );
}
