# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase. The repo has a single context: one root `GLOSSARY.md` and one `docs/adr/`.

## Before exploring, read these

- **`GLOSSARY.md`** at the repo root.
- **`docs/adr/`**: read the ADRs that touch the area you're about to work in.

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `GLOSSARY.md`. Don't drift to synonyms the glossary explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for the `domain-modeling` skill, which edits the glossary and records ADRs).

## Flag ADR conflicts

If your output contradicts an existing ADR, surface it explicitly rather than silently overriding:

> _Contradicts ADR-0004 (Price capped at the Maximum Price), but worth reopening because…_

To change a decision, amend the ADR (see the amendment in ADR-0001) or record a new one that says which ADR it supersedes.
