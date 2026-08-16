# Finance Playground

A multi-tenant budgeting and accountability system for a federated organisation — one head office, many semi-autonomous teams, four chained approval workflows that run a budget from annual plan through disbursement to monthly report.

**Live demo:** _(pending deploy)_ · **Log in with any account below, password `password123!`**

> This is a public, anonymised playground built from a system that runs in production. Client identity, real data and domain-specific vocabulary have been removed from the entire history, not just the current version. The engineering is unchanged.

---

## What problem it solves

A federated organisation has a central treasury and a dozen teams that each plan, spend and report on their own budget. The failure mode is not accounting — it is **sequencing**: a team submits a report for money it was never approved to spend, an approver signs off on a stage whose prerequisite was rejected, a disbursement happens against a budget line that has since changed.

The system makes those states unreachable. Every stage declares what must be complete before it opens, and where a rejection sends the work back to.

## The four workflows

Each is a chain of numbered stages. A stage is a **form**, an **approval**, a **final** (the record other workflows read), or a **revision**.

| | | |
|---|---|---|
| **PP** — Period Setup | 7 stages | The head office opens a budget period: the period plan and its service codes, the questionnaire, budget ceilings, SOP documents → approval → the annual period record |
| **PK** — Programme Planning | 6 stages | A team proposes its programme and budget → narrative approval → budget approval → planning forum → the team's annual programme |
| **PABD** — Disbursement | 6 stages | Monthly release: checklist → budget amendment → amendment approval → transfer approval → transfer evidence → the monthly disbursement record |
| **PRBL** — Reporting | 6 stages | Monthly accountability: activity report with receipts → narrative and budget approval → refund of unspent funds → final review → the monthly report |

PK cannot start until PP has produced a period. PABD and PRBL run monthly against PK's approved programme, and PRBL's refund stage feeds back into the next PABD.

## The part worth reading

The workflow engine is **declarative**. A workflow is a class implementing [`WorkflowDefinition`](app/Contracts/WorkflowDefinition.php) that returns a step table — nothing more:

```php
'PP05' => [
    'table'           => null,
    'type'            => StepType::Approval,
    'label'           => 'Persetujuan',
    'prerequisites'   => ['PP04'],
    'rejectionTarget' => 'PP01',
],
```

Everything else is generic. Prerequisite checking, stage unlocking, rejection routing, permission gating and progress display all read the definition rather than branching on workflow type. Adding a fifth workflow means [adding a fifth definition](app/Workflows/) — no changes to the controllers, the progress component, or the permission layer.

Also worth a look:

- **[`app/Workflows/`](app/Workflows/)** — the four definitions, side by side. Reading them together is the fastest way to understand the domain.
- **Permissions are per team, per role, per route.** A user holds a role inside a team; roles carry route-level permissions; `check.permission` middleware enforces them. Users with more than one role choose which they are acting as ([`role-selector`](app/Http/Controllers/SwitcherController.php)).
- **The multi-tenant chain** is Organization → Workspace → Team. Data is scoped at the team, visibility at the workspace.

## A note on language

**The interface is in Indonesian**, because the organisation it was built for works in Indonesian. Stage labels in the code are Indonesian too. The glossary below covers most of what you will see:

| | | | |
|---|---|---|---|
| *anggaran* — budget | *kegiatan* — activity | *pengajuan* — submission | *persetujuan* — approval |
| *laporan* — report | *bukti / nota* — receipt | *pencairan* — disbursement | *revisi* — revision |
| *plafon* — ceiling, cap | *realisasi* — actual spend | *bidang pelayanan* — service area | *periode tahunan* — annual period |

## Demo accounts

**The login form arrives pre-filled — just press Masuk.** It signs you in as `test@demo.test`, which holds *every* role in the system, so you can submit as a division, approve as the treasury and sign off as monitoring without logging out. The role switcher is how you change hats.

**The demo database resets every 15 minutes.** Anything you create is meant to be thrown away.

Every account uses the password `password123!`. To see the permission model actually biting, log in as a single-role account instead:

| Account | Sees |
|---|---|
| `test@demo.test` | Everything. The pre-filled account |
| `superadmin@demo.test` | Every permission in the catalogue |
| `bendahara-ops@demo.test` | One division — its own planning, disbursement and reporting only |
| `evaluator-narasi@demo.test` | Narrative approval desks (PK02A, PRBL02A) and nothing else |
| `evaluator-anggaran@demo.test` | Budget approval desks (PK02B, PRBL02B) |
| `bu1@demo.test` | Treasury — transfer approval and final report sign-off |
| `staff-kp@demo.test` | Head office — uploading transfer receipts at PABD04, read-only elsewhere |

### What is waiting for you

The seed parks work at every desk rather than leaving the app empty:

| | |
|---|---|
| **Every actionable step** | Has **exactly one** workflow parked on it. Nothing is waiting on the same desk twice, and no step is unreachable |
| **Operasional, Pemasaran, Teknologi Informasi** | Run the full chain — programme approved, months disbursed, monthly reports filed |
| **SDM, Keuangan, Legal, Pengadaan** | Parked across the PK steps: one draft, one awaiting narrative approval, one awaiting budget approval, one awaiting final sign-off |
| **Periode Anggaran 2027** | A second, empty workspace. Start a PP there to walk the annual frame from PP01 with nothing pre-filled |

Registration and password reset are switched off on the demo — the routes are removed, not just the buttons.

Try this: log in, switch to a division's Bendahara Tim role and submit a monthly disbursement, then switch to Bendahara Umum 1 and approve it.

## Stack

Laravel 12 · PHP 8.4 · Inertia 2 · React 19 · TypeScript · Tailwind 4 · Pest 4 · SQLite in the demo, PostgreSQL in production.

Route helpers are generated for the frontend by [Wayfinder](https://github.com/laravel/wayfinder), so a renamed route breaks the TypeScript build rather than the running page.

## Running it

```bash
docker build -t finance-playground . && docker run -p 8080:80 finance-playground
```

One container, no external services. It seeds itself on boot and resets every 15 minutes; set `DEMO_RESET=false` to stop that.

Locally, without Docker:

```bash
composer setup && npm run dev
```

`composer setup` copies `.env.example`, generates a key, migrates and builds. Set `DB_CONNECTION=sqlite` in `.env` first if you would rather not run PostgreSQL. Then `php artisan migrate:fresh --seed` for the demo data.

## Tests

```bash
php artisan test
```

Around 860 Pest tests cover the workflow engine, the permission layer and the stage transitions.

⚠️ **No test calls a seeder**, so the seed path sits outside the suite. That gap has already cost once: a rename split a derived role key from its hardcoded lookups, every test passed, and seeding died.

## How this was built

The direction, architecture and review are mine; a large share of the code was written by AI working inside that direction. The workflow engine's declarative shape, the multi-tenant boundary, the permission model and the decision to make illegal sequences unrepresentable are design calls I made and can defend line by line. `CLAUDE.md` and `.claude/skills/` are in this repository on purpose — they are how the work was actually directed.

