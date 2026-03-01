import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { logout } from '@/routes';

export default function NoAccess() {
    return (
        <AuthLayout
            title="Akses Belum Tersedia"
            description="Anda belum memiliki role dalam sistem ini"
        >
            <Head title="Akses Belum Tersedia" />

            <div className="flex flex-col gap-4 text-center">
                <p className="text-sm text-muted-foreground">
                    Hubungi administrator untuk mendapatkan akses.
                </p>

                <Button variant="outline" asChild>
                    <Link href={logout()} as="button">
                        Keluar
                    </Link>
                </Button>
            </div>
        </AuthLayout>
    );
}
