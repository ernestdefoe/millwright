# Millwright

Install, update and roll back Flarum extensions — and Flarum itself — on any host.

> **Phase 2 of 6.** The safety core is built and tested. There is no admin UI,
> no Composer integration and no discovery yet. See the scope document for the
> plan.

## Why

Flarum's Extension Manager fails in a way that takes sites down.

This is not a criticism of the people who wrote it. It is an older design that
predates Flarum 2, and the specific code involved has not been touched upstream
since 2024. A fix for the worst of it has been offered back — see
[flarum/framework#5034](https://github.com/flarum/framework/pull/5034) — but
some of what follows cannot be fixed without changing the shape of the thing,
which is why this exists separately rather than as a patch. It runs Composer
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
| 2 | Journal apply + rollback | ✅ done |
| 3 | Step driver | ✅ **this** — admin UI still to come |
| 4 | Discovery, Flarum 2 only | in progress |
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

`ResumabilityTest` goes further and kills at *random* points, repeatedly, until
a run completes — a better model of a real host, where the cut lands wherever
the clock happens to be. One case kills in the single gap that matters: between
doing an item and recording it. That item is then redone on resume, and the test
asserts the repeat rather than pretending it cannot happen. Everything in the
applier is built so a repeat is a no-op.

## How it survives a 30-second host

Nothing loops. A call to `StepRunner::step()` does exactly one item and returns,
so progress is a function of how many times something calls it — the admin screen
polling, a cron tick, or a queue worker, interchangeably. A host that cuts every
request at thirty seconds can therefore finish a ten-minute update, which no
amount of making the update faster would have achieved.
