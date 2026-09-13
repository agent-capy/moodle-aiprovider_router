#!/usr/bin/env bash
# Run a CI step and, when it fails, surface enough of its output as GitHub
# annotations to work out what went wrong.
#
# Downloading job logs needs an authenticated token. This project has none, so a
# failure would otherwise reach the person debugging it as "exit code 1" and
# nothing else. Annotations are readable without a token.
#
# Two annotations are emitted on failure, because neither alone is enough:
#   - the tail, which carries the counts and the rerun command;
#   - the first thing that looks like the actual error, which for Behat and
#     PHPUnit is usually thousands of lines above the tail, buried under a stack
#     trace of framework internals.
#
# Usage: run-step.sh <title> <command> [args...]
set -o pipefail

title="$1"
shift
log="$(mktemp)"

if "$@" 2>&1 | tee "$log"; then
  exit 0
fi

python3 -c "
import pathlib, re, sys

log = pathlib.Path(sys.argv[1]).read_text(errors='replace')
title = sys.argv[2]


def annotate(name, text):
    encoded = text.replace('%', '%25').replace(chr(13), '%0D').replace(chr(10), '%0A')
    print('::error title=' + name + '::' + encoded)


# The first line that looks like a diagnosis rather than a frame of the stack the
# diagnosis arrived on.
marker = re.search(
    r'(?m)^.*(Fatal error|Uncaught|Exception:|_exception|Failed asserting|'
    r'Error:|Notice:|Warning:|Undefined |Behat\\\\.*Exception|Failed scenarios).*$',
    log,
)
if marker:
    start = max(0, marker.start() - 500)
    annotate(title + ' first error', log[start:start + 3500])

annotate(title + ' failed', log[-3500:])
" "$log" "$title"
exit 1
