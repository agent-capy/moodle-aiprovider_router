# Contributing to local_airouter

Thanks for your interest. This plugin is developed as part of a 2026 research and
development project funded by the Moodle Association of Japan (MAJ), and is released
under the GNU GPL v3 or later.

## Reporting issues

Please use the GitHub issue tracker. Include:

- Moodle version (e.g. 5.0, Build 20250414) and PHP version
- The plugin version (`version.php` → `$plugin->release`)
- What you expected, what happened, and any error from the Moodle logs

## Development setup

The plugin lives at `ai/provider/router/` inside a Moodle installation.

Supported versions: **Moodle 5.0 and later**. Development targets 5.0 (the lowest
supported release) so that APIs introduced in 5.1/5.2 are not used accidentally.

## Coding style

Moodle coding style is enforced in CI via
[moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci). Before opening a
pull request, please make sure `phplint`, `phpcs`, `phpmd` and `phpunit` pass.

Comments and identifiers must be written in **English**. The Moodle standard requires
comments to start with a capital letter and end in a full stop, which non-Latin scripts
cannot satisfy.

This repository ships a pre-commit hook that checks syntax, coding style, and tests
before every commit. Enable it once after cloning:

```bash
git config core.hooksPath .githooks
```

## Pull requests

- Branch from `main` and keep one logical change per pull request
- Include tests for behaviour changes
- Update `version.php` only when asked; the maintainer handles release versioning
