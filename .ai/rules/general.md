---
paths:
  - '**'
---

# General

## Commits: Conventional Commits, no co-author trailers
Commit messages follow Conventional Commits: `type(scope): subject`, imperative
mood, lowercase subject, no trailing period. Types in use: feat, fix, chore,
refactor, test, docs, perf, build, ci.

Never add `Co-Authored-By:` trailers, "Generated with" footers, or any other
attribution to an AI tool or agent. The commit message describes the change and
nothing else.

Keep unrelated changes in separate commits — do not fold pre-existing working
tree drift (regenerated tooling files, vendor guideline updates) into a feature
commit.

## PHPStan: Always run on modified PHP files
After modifying any PHP file, run PHPStan at level 8 on the modified files:

```bash
vendor/bin/phpstan analyze --level 8 path/to/modified/file.php
```

Fix all reported issues before considering the work complete. PHPStan catches
type errors, undefined methods, and logic issues that tests might miss.

## Always run tests with --tia and --parallel
When running tests, always use both --tia (Test Impact Analysis for 22x speed) and --parallel flags:

```bash
./vendor/bin/pest --tia --parallel
```

Or via artisan:
```bash
php artisan test --parallel
```

Never run tests without these flags in local development. Only CI should run the full suite without --tia.
