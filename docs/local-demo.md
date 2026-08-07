# AI Operating System local demonstration

## Purpose

The local demonstration creates deterministic, simulation-only projects for
evaluating the complete MVP workflow. No repository write, pull request, merge,
CI result, or deployment produced by the demonstration is real or verified.

## Prepare the environment

```bash
./bin/demo
```

To prepare only the seeded data, run:

```bash
./vendor/bin/sail artisan app:demo:prepare --json
```

Start the application with `./bin/dev`, then open `http://localhost`. Use the
credentials printed by demo preparation.

## Demonstration projects and scenarios

The seed includes happy-path, conflicting-document, transient Notion failure,
and high-risk merge-recommendation projects. List the complete deterministic
scenario catalog with:

```bash
./vendor/bin/sail artisan simulation:scenarios --seed=1
```

Inspect one scenario with `simulation:scenarios happy-path --seed=1 --json`.

## Simulation warning

Every resulting artifact remains simulated and unverified. The demonstration
does not authorize real repository writes, CI, merging, or deployment.
