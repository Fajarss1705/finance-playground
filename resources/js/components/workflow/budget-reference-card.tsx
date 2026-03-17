import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { cn, formatRupiah } from '@/lib/utils';

export type BudgetCounterData = {
    ppLabel: string | null;
    tahun: number | null;
    teamName: string;
    plafon: number;
    accepted: number;
    review: number;
    pendingRaker: number;
    draft: number;
    proposalAccepted: number;
    proposalReview: number;
    proposalDraft: number;
    pkIni?: number;
};

type BudgetReferenceCardProps = {
    counter: BudgetCounterData;
    /** For PK01: client-side computed total from form state (overrides counter.pkIni) */
    pkIniOverride?: number;
    /** 'form' = PK01 (detailed warning), 'approval' = PK02/03 (short warning), 'readonly' = show/PK04 (no warning) */
    variant?: 'form' | 'approval' | 'readonly';
    /** For PK03: hard block mode — shows error instead of warning */
    hardBlock?: boolean;
};

export default function BudgetReferenceCard({ counter, pkIniOverride, variant = 'readonly', hardBlock = false }: BudgetReferenceCardProps) {
    const [open, setOpen] = useState(true);

    const pkIni = pkIniOverride ?? counter.pkIni ?? 0;
    const sisaRaker = counter.plafon - counter.accepted;
    const sisaAll = counter.plafon - counter.accepted - counter.review - counter.pendingRaker - counter.draft;
    const isOverBudget = pkIni > sisaRaker && sisaRaker > 0;

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <div className="rounded-lg border bg-card shadow-sm">
                <CollapsibleTrigger asChild>
                    <button className="flex w-full items-center justify-between border-b bg-muted/50 px-4 py-3 text-left hover:bg-muted/70 transition-colors">
                        <div className="flex items-center gap-2">
                            <h3 className="text-sm font-medium">Referensi Anggaran</h3>
                            {counter.ppLabel && <Badge variant="secondary" className="text-xs">{counter.ppLabel}</Badge>}
                        </div>
                        <ChevronDown className={cn('size-4 text-muted-foreground transition-transform', open && 'rotate-180')} />
                    </button>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div className="space-y-4 px-4 py-3">
                        {/* Section 1: Live Data */}
                        <Section title="Live Data">
                            <Row>
                                <Cell
                                    label={`Plafon Tim (${counter.teamName})${counter.tahun ? ` (${counter.tahun})` : ''}`}
                                    value={counter.plafon}
                                    highlight="primary"
                                />
                                <Cell label="Sudah Ditetapkan Raker (PK04)" value={counter.accepted} />
                            </Row>
                            <Row>
                                <Cell label="Draft (PK01)" value={counter.draft} />
                                <Cell label="Sedang Direview (PK02A, PK02B)" value={counter.review} />
                            </Row>
                            <Row>
                                <Cell label="Menunggu Approval Raker (PK03)" value={counter.pendingRaker} />
                            </Row>
                        </Section>

                        {/* Section 2: Sisa Plafon */}
                        <Section title="Sisa Plafon">
                            <Row>
                                <Cell label="Sisa (Plafon − Ditetapkan Raker)" value={sisaRaker} highlight={sisaRaker < 0 ? 'danger' : undefined} />
                                <Cell label="Sisa (Plafon − PK01 − PK02A/B − PK03 − Ditetapkan Raker)" value={sisaAll} highlight={sisaAll < 0 ? 'danger' : undefined} />
                            </Row>
                        </Section>

                        {/* Section 3: Di Luar Plafon (Proposal) */}
                        <Section title="Di Luar Plafon (Proposal)">
                            <Row>
                                <Cell label="Sudah Diterima dan Ditetapkan" value={counter.proposalAccepted} />
                            </Row>
                            <Row>
                                <Cell label="Sedang Direview" value={counter.proposalReview} muted />
                                <Cell label="Draft Proposal" value={counter.proposalDraft} muted />
                            </Row>
                        </Section>

                        {/* PK Ini footer */}
                        {variant !== 'readonly' && (
                            <div className="border-t pt-3">
                                <div className="flex items-center gap-2">
                                    <span className="text-sm text-muted-foreground">Di antaranya, PK ini:</span>
                                    <span className={cn('text-sm font-semibold tabular-nums', isOverBudget && 'text-amber-600 dark:text-amber-400')}>
                                        Rp {formatRupiah(pkIni)}
                                    </span>
                                </div>
                                {isOverBudget && hardBlock && (
                                    <p className="mt-1 text-xs font-medium text-destructive">
                                        Tidak dapat disetujui — total anggaran melebihi sisa plafon.
                                    </p>
                                )}
                                {isOverBudget && !hardBlock && variant === 'form' && (
                                    <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                        Total anggaran PK ini melebihi sisa plafon. Submit tetap diizinkan, namun akan diblokir saat Approval Raker (PK03).
                                    </p>
                                )}
                                {isOverBudget && !hardBlock && variant === 'approval' && (
                                    <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                        Total anggaran PK ini melebihi sisa plafon. Akan diblokir saat Approval Raker (PK03).
                                    </p>
                                )}
                            </div>
                        )}
                        {variant === 'readonly' && pkIni > 0 && (
                            <div className="border-t pt-3">
                                <div className="flex items-center gap-2">
                                    <span className="text-sm text-muted-foreground">PK ini:</span>
                                    <span className="text-sm font-semibold tabular-nums">
                                        Rp {formatRupiah(pkIni)}
                                    </span>
                                </div>
                            </div>
                        )}
                    </div>
                </CollapsibleContent>
            </div>
        </Collapsible>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div>
            <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">{title}</p>
            <div className="space-y-2">{children}</div>
        </div>
    );
}

function Row({ children }: { children: React.ReactNode }) {
    return <div className="grid gap-3 sm:grid-cols-2">{children}</div>;
}

function Cell({ label, value, highlight, muted }: { label: string; value: number; highlight?: 'primary' | 'danger'; muted?: boolean }) {
    return (
        <div className={cn(
            'rounded-md border px-3 py-2',
            highlight === 'primary' && 'border-primary/30 bg-primary/5 dark:bg-primary/10',
            highlight === 'danger' && 'border-destructive/30 bg-destructive/5 dark:bg-destructive/10',
        )}>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className={cn(
                'text-sm font-semibold tabular-nums',
                highlight === 'danger' && 'text-destructive',
                muted && 'text-muted-foreground',
            )}>
                Rp {formatRupiah(value)}
            </p>
        </div>
    );
}
