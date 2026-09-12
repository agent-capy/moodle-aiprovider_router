#!/usr/bin/env bash
# Run a CI step and, when it fails, surface the tail of its output as a GitHub
# annotation.
#
# Downloading job logs needs an authenticated token. This project has none, so a
# failure would otherwise reach the person debugging it as "exit code 1" and
# nothing else. Annotations are readable without a token.
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
import pathlib, sys
tail = pathlib.Path(sys.argv[1]).read_text(errors='replace')[-4000:]
enc = tail.replace('%', '%25').replace(chr(13), '%0D').replace(chr(10), '%0A')
print('::error title=' + sys.argv[2] + ' failed::' + enc)
" "$log" "$title"
exit 1
