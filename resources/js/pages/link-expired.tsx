import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { dashboard } from '@/routes';

export default function LinkExpired() {
    return (
        <AuthLayout
            title="Link Kedaluwarsa"
            description="Link yang Anda klik sudah tidak berlaku"
        >
            <Head title="Link Kedaluwarsa" />

            <div className="flex flex-col gap-4 text-center">
                <p className="text-sm text-muted-foreground">
                    Link ini sudah melewati batas waktu 7 hari atau tidak valid.
                    Silakan buka notifikasi dari dalam aplikasi.
                </p>

                <Button asChild>
                    <Link href={dashboard()}>
                        Kembali ke Dashboard
                    </Link>
                </Button>
            </div>
        </AuthLayout>
    );
}
