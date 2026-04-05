import { Transition } from '@headlessui/react';
import { Form, Head, Link, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pengaturan', href: edit() },
    { title: 'Profil', href: edit() },
];

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pengaturan Profil" />

            <h1 className="sr-only">Pengaturan Profil</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Informasi profil"
                        description="Perbarui informasi profil Anda"
                    />

                    <Form
                        {...ProfileController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        className="space-y-6"
                    >
                        {({ processing, recentlySuccessful, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">
                                        Nama{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </Label>

                                    <Input
                                        id="name"
                                        className="mt-1 block w-full"
                                        defaultValue={auth.user.name}
                                        name="name"
                                        required
                                        autoComplete="name"
                                        placeholder="Nama lengkap"
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.name}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">
                                        Alamat email{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </Label>

                                    <Input
                                        id="email"
                                        type="email"
                                        className="mt-1 block w-full"
                                        defaultValue={auth.user.email}
                                        name="email"
                                        required
                                        autoComplete="username"
                                        placeholder="Alamat email"
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.email}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="phone_number">
                                        Nomor telepon{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </Label>

                                    <Input
                                        id="phone_number"
                                        type="tel"
                                        className="mt-1 block w-full"
                                        defaultValue={
                                            auth.user.phone_number ?? ''
                                        }
                                        name="phone_number"
                                        required
                                        autoComplete="tel"
                                        placeholder="08123456789"
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.phone_number}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="jabatan">
                                        Jabatan{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </Label>

                                    <Input
                                        id="jabatan"
                                        className="mt-1 block w-full"
                                        defaultValue={
                                            auth.user.jabatan ?? ''
                                        }
                                        name="jabatan"
                                        required
                                        placeholder="Contoh: Dewan, Ketua Divisi Kepemudaan"
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.jabatan}
                                    />
                                </div>

                                {/* TODO: avatar upload (optional, max 5MB, compressed on save) */}

                                {mustVerifyEmail &&
                                    auth.user.email_verified_at === null && (
                                        <div>
                                            <p className="-mt-4 text-sm text-muted-foreground">
                                                Alamat email Anda belum
                                                diverifikasi.{' '}
                                                <Link
                                                    href={send()}
                                                    as="button"
                                                    className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                                >
                                                    Klik di sini untuk mengirim
                                                    ulang email verifikasi.
                                                </Link>
                                            </p>

                                            {status ===
                                                'verification-link-sent' && (
                                                <div className="mt-2 text-sm font-medium text-green-600">
                                                    Link verifikasi baru telah
                                                    dikirim ke alamat email
                                                    Anda.
                                                </div>
                                            )}
                                        </div>
                                    )}

                                <div className="flex items-center gap-4">
                                    <Button
                                        disabled={processing}
                                        data-test="update-profile-button"
                                    >
                                        Simpan
                                    </Button>

                                    <Transition
                                        show={recentlySuccessful}
                                        enter="transition ease-in-out"
                                        enterFrom="opacity-0"
                                        leave="transition ease-in-out"
                                        leaveTo="opacity-0"
                                    >
                                        <p className="text-sm text-neutral-600">
                                            Tersimpan
                                        </p>
                                    </Transition>
                                </div>
                            </>
                        )}
                    </Form>
                </div>

                <DeleteUser />
            </SettingsLayout>
        </AppLayout>
    );
}
