# Shared code (`includes/core/`)

Anything in this folder is **shared across every DS.Emotion plugin**.

Put reusable code here (the update checker, security helpers, common classes).
When you change something in this folder and push to `main`, a GitHub Action
automatically opens a pull request in every plugin repo listed in
`.github/sync.yml`, copying the update across. Merge those PRs and each plugin
has the change.

Keep plugin-specific code OUT of this folder — only put things here that every
plugin should have.
