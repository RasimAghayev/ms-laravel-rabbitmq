# Contributing

Thank you for considering contributing to this project!

## Report a Bug

Open an issue describing the expected vs. actual behavior, steps to reproduce,
and the environment (language version, platform, relevant dependencies).

## Request a Feature

Open an issue with the use case and why it belongs in this project. Discuss
before opening a pull request.

## Pull Request Workflow

This repository uses a 3-branch model: `preview` (integration) ->
`test` (stabilized integration) -> `main` (production-ready, stays the
GitHub default branch). Every change arrives by PR; nothing is pushed
directly to any of the three.

1. Cut a branch named `phase-<n>/<topic>` (for example `phase-1/docs-api`) from `preview`.
2. Make your change. Keep commits scoped to a single concern.
3. Commit with conventional commits (e.g. `docs: ...`, `feat: ...`, `fix: ...`).
4. Open the PR against `preview`. `make verify` (the Docker build + test stage for both apps) must be green before merge — never `--no-verify`.
5. Rebase onto `preview` before merging (rebase-merge; avoid merge commits unless the PR is a single logical change, in which case squash is fine).
6. Delete the feature branch after it is merged.

`preview` is periodically promoted to `test`, and `test` to `main`, once
each is green — that promotion is a separate, maintainer-run step, not
something an individual feature PR does itself.

## Coding Standards

- Follow the project's existing code style and patterns.
- Run static analysis/linting tools before pushing (if configured).
- Keep changes focused; avoid committing commented-out code or dead weight.
- Update documentation when changing user-facing behavior.

## Testing

- Run the test suite locally before submitting a PR.
- Add tests for new functionality.
- Ensure all existing tests pass.

## Commit Authorship

No AI attribution in commits. Do not add `Co-Authored-By` or `Claude-Session`
trailers.