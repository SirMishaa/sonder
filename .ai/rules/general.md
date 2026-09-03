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
