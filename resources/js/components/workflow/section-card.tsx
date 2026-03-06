import { cn } from '@/lib/utils';

type SectionCardProps = {
    title: string;
    children: React.ReactNode;
    className?: string;
    headerRight?: React.ReactNode;
};

export default function SectionCard({ title, children, className, headerRight }: SectionCardProps) {
    return (
        <div className={cn('rounded-lg border bg-card shadow-sm', className)}>
            <div className="flex items-center justify-between border-b bg-muted/50 px-4 py-3">
                <h3 className="text-sm font-medium">{title}</h3>
                {headerRight}
            </div>
            <div className="px-4 py-3">{children}</div>
        </div>
    );
}
