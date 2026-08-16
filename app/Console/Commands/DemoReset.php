<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Return the public demo to its seeded state.
 *
 * The database was the easy half. Uploaded files and generated exports live on
 * disk, and migrate:fresh only removes the rows that point at them -- so
 * whatever a visitor uploaded stayed on the volume, orphaned, until the Machine
 * happened to restart. Over a day of traffic that is unbounded growth backing
 * records that no longer exist.
 *
 * 🔴 This is one command rather than a second scheduled job on purpose. Two
 * entries on the same fifteen-minute tick drift: one runs, the other is skipped
 * by withoutOverlapping, and the demo is left holding files whose rows are gone.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Wipe and re-seed the public demo, files included';

    /**
     * Emptied of contents, never removed. storage/app/public is the target of
     * the public/storage symlink, so deleting the directory itself leaves a
     * dangling link and every asset URL under it 404s.
     */
    private const WIPE = [
        'app/private',
        'app/public',
    ];

    public function handle(): int
    {
        // 🔴 The same config guard the scheduler uses, repeated here because a
        // command can also be run by hand. Never keyed on APP_ENV -- this must
        // be impossible to trigger anywhere but the throwaway playground.
        if (! config('demo.reset')) {
            $this->error('demo.reset is off. Refusing to wipe anything.');

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);

        $removed = 0;

        foreach (self::WIPE as $relative) {
            $path = storage_path($relative);

            if (! File::isDirectory($path)) {
                continue;
            }

            foreach (File::directories($path) as $directory) {
                File::deleteDirectory($directory);
                $removed++;
            }

            foreach (File::files($path) as $file) {
                // .gitignore is what keeps these directories in the repository
                // at all; deleting it makes the next clone miss them entirely.
                if ($file->getFilename() === '.gitignore') {
                    continue;
                }

                File::delete($file->getPathname());
                $removed++;
            }
        }

        $this->info("Demo reset: database re-seeded, {$removed} stored entries removed.");

        return self::SUCCESS;
    }
}
