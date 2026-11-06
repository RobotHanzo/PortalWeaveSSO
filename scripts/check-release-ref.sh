#!/usr/bin/env bash
set -euo pipefail
tag="${1:-}"
expected="${2:-}"
[[ "$tag" =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ && "$expected" =~ ^[a-f0-9]{40}$ ]] || {
  printf 'A stable version tag and exact commit SHA are required.\n' >&2
  exit 1
}
refs="$(git ls-remote --exit-code --tags origin "refs/tags/$tag" "refs/tags/$tag^{}")"
# Prefer the peeled commit of an annotated tag; lightweight tags advertise the commit directly.
actual="$(printf '%s\n' "$refs" | awk 'NR == 1 { direct = $1 } $2 ~ /\^\{\}$/ { peeled = $1 } END { print peeled ? peeled : direct }')"
[[ "$actual" == "$expected" ]] || {
  printf 'The remote release tag changed after verification; refusing publication.\n' >&2
  exit 1
}
printf 'Remote tag %s still matches the verified commit.\n' "$tag"
