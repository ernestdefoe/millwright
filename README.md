# Millwright

Install, update and roll back Flarum extensions — and Flarum itself — on any host.

> **Phase 2 of 6.** The safety core is built and tested. There is no admin UI,
> no Composer integration and no discovery yet. See the scope document for the
> plan.

## Why

Flarum's Extension Manager fails in a way that takes sites down. It runs Composer
inside the PHP worker serving the request, caps memory at 1 GB from inside that
process, and swaps the vendor directory by **deleting it and moving a new one
over the top** — leaving the forum with no `vendor/` at all for as long as that
takes. When the job is killed inside that window, the site stops booting, the
task row still says `running`, and every later update is then silently skipped.

Millwright is built the other way round: **nothing is deleted, and every step is
written down before it is taken.**

## The mechanism

Applying a change is two renames, never a delete:

```
vendor/<pkg>   →  trash/<pkg>@<version>     the old one is kept
staging/<pkg>  →  vendor/<pkg>              the new one arrives
```

Both are `rename()`, so each is atomic. The package is absent between them and
only between them — microseconds, for one package. Nothing is removed during an
apply at all: the old version waits in the trash, which is what makes rollback
possible long after the fact.

Rollback replays the journal backwards. Its cost is proportional to what changed,
not to the size of the tree, so it works on a host with a disk quota where
copying `vendor/` would not.

## Status

| Phase | | |
|---|---|---|
| 1 | Plan, measured against a constrained host | ✅ done |
| 2 | Journal apply + rollback | ✅ **this** |
| 3 | Step driver + admin UI | — |
| 4 | Discovery, Flarum 2 only | — |
| 5 | Core updates + compatibility verdict | — |
| 6 | Symlinked vendor slots for capable hosts | — |

## Tests

```
composer install && vendor/bin/phpunit
```

The suite that matters is `CrashRecoveryTest`. It runs a real apply in a
subprocess and **SIGKILLs it at every step in the sequence** — before anything is
touched, after the old version is stashed but before the new one lands, after it
lands but before the journal records it, and after the record is complete — then
rolls back and asserts the tree is byte-for-byte what it was.

A thrown exception would be a weaker test: it unwinds the stack and runs
destructors, which a host killing an overrunning request does not.
