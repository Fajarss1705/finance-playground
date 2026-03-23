import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { store } from '@/routes/register';

export default function Register() {
    return (
        <AuthLayout
            title="Buat akun baru"
            description="Isi data di bawah untuk membuat akun Anda"
        >
            <Head title="Daftar" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    Nama{' '}
                                    <span className="text-destructive">
                                        *
                                    </span>
                                </Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Nama lengkap"
                                />
                                <InputError
                                    message={errors.name}
                                    className="mt-2"
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
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
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
                                    required
                                    tabIndex={3}
                                    autoComplete="tel"
                                    name="phone_number"
                                    placeholder="08123456789"
                                />
                                <InputError
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
                                    type="text"
                                    required
                                    tabIndex={4}
                                    name="jabatan"
                                    placeholder="Contoh: Dewan, Ketua Divisi Kepemudaan"
                                />
                                <InputError
                                    message={errors.jabatan}
                                />
                            </div>

                            {/* TODO: avatar upload (optional, max 5MB, compressed on save) */}

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    Kata sandi{' '}
                                    <span className="text-destructive">
                                        *
                                    </span>
                                </Label>
                                <Input
                                    id="password"
                                    type="password"
                                    required
                                    tabIndex={5}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Kata sandi"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Konfirmasi kata sandi{' '}
                                    <span className="text-destructive">
                                        *
                                    </span>
                                </Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    required
                                    tabIndex={6}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Konfirmasi kata sandi"
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={7}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                Buat akun
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            Sudah punya akun?{' '}
                            <TextLink href={login()} tabIndex={8}>
                                Masuk
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
