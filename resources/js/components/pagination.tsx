import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginationProps = {
    links: PaginationLink[];
    currentPage: number;
    lastPage: number;
    total: number;
};

export default function Pagination({
    links,
    currentPage,
    lastPage,
    total,
}: PaginationProps) {
    if (lastPage <= 1) {
        return null;
    }

    const prevLink = links[0];
    const nextLink = links[links.length - 1];

    return (
        <div className="flex items-center justify-between pt-4">
            <p className="text-sm text-muted-foreground">
                Halaman {currentPage} dari {lastPage} ({total} data)
            </p>
            <div className="flex gap-2">
                {prevLink?.url ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={prevLink.url} prefetch>
                            Sebelumnya
                        </Link>
                    </Button>
                ) : (
                    <Button variant="outline" size="sm" disabled>
                        Sebelumnya
                    </Button>
                )}
                {nextLink?.url ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={nextLink.url} prefetch>
                            Selanjutnya
                        </Link>
                    </Button>
                ) : (
                    <Button variant="outline" size="sm" disabled>
                        Selanjutnya
                    </Button>
                )}
            </div>
        </div>
    );
}
