# License Policy — Task Tracker v1.0

Authoritative reference: spec §11 of `docs/superpowers/specs/2026-05-10-task-tracker-design.md`.

## Allowed licenses

Direct dependencies and their transitive closure MUST be licensed under one of:

| SPDX identifier | Family |
|---|---|
| `MIT` | Permissive |
| `Apache-2.0` | Permissive (patent grant) |
| `BSD-2-Clause` | Permissive |
| `BSD-3-Clause` | Permissive |
| `ISC` | Permissive |
| `0BSD` | Public-domain equivalent |

## Rejected licenses

Adding a dependency under any of the following is a build-stopping error:

- `GPL-2.0`, `GPL-3.0` (and any `-only` / `-or-later` variant)
- `LGPL-2.1`, `LGPL-3.0` (any variant)
- `AGPL-3.0` (any variant)
- `SSPL-1.0`
- Anything labelled `proprietary`, `Commercial`, or `UNLICENSED`

Rationale: this codebase is intended to be embeddable inside other internal tooling without copyleft propagation, and to be shippable to customer sites whose legal review forbids SSPL/AGPL.

## Self-contained vendor copies

Per spec §11, frontend libraries (Bootstrap 5, Chart.js, cytoscape.js, cytoscape-dagre) MUST be copied into `apps/{admin,public}/public/vendor/` rather than loaded from a CDN. Each copy retains its original `LICENSE` file alongside the minified asset.

## Auditing

`composer licenses` on each sub-package (`lib/`, `apps/admin/`, `apps/public/`) MUST list only allowed licenses. A future task (v1.1) will add a CI check that fails on a non-allowed SPDX identifier in any `composer.lock`.

## Adding a dependency

1. Resolve its SPDX identifier (Packagist > package page > license field).
2. If it's in the allowed list, add and proceed.
3. If it's in the rejected list, find an alternative.
4. If it's ambiguous (dual-licensed, custom text), open a doc PR amending this policy before adding.

## Project's own license

This project is released under `MIT` (see future `LICENSE` file).
