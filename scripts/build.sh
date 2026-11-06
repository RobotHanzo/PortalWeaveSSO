#!/usr/bin/env bash
# Build only the shared release manifest, then independently inspect the archive.
set -euo pipefail

requested_slug=''
requested_version=''
slug_given=false
version_given=false
while (($#)); do
  case "$1" in
    --slug|--version)
      flag="$1"
      if (($# < 2)) || [[ -z "$2" || "$2" == --* ]]; then
        printf '%s requires one value.\n' "$flag" >&2
        exit 2
      fi
      if [[ "$flag" == --slug ]]; then
        if "$slug_given"; then printf 'Duplicate --slug option.\n' >&2; exit 2; fi
        requested_slug="$2"
        slug_given=true
      else
        if "$version_given"; then printf 'Duplicate --version option.\n' >&2; exit 2; fi
        requested_version="$2"
        version_given=true
      fi
      shift 2
      ;;
    *) printf 'Usage: bash scripts/build.sh [--slug SLUG] [--version X.Y.Z]\n' >&2; exit 2 ;;
  esac
done

for command in node zip unzip mktemp; do
  command -v "$command" >/dev/null || { printf 'Required build command is unavailable: %s\n' "$command" >&2; exit 1; }
done
# User archiver defaults must not alter the explicit manifest or extraction flags.
unset ZIPOPT UNZIP UNZIPOPT ZIPINFO ZIPINFOOPT
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo="$(cd -- "$script_dir/.." && pwd -P)"
metadata="$(bash "$script_dir/release.sh" validate)"
identity="$(node - "$metadata" "$requested_slug" "$requested_version" <<'NODE'
const metadata = JSON.parse(process.argv[2]);
if (process.argv[3] && process.argv[3] !== metadata.slug) throw new Error('Package slug must match the plugin Text Domain.');
if (process.argv[4] && process.argv[4] !== metadata.version) throw new Error('Requested package version must match validated plugin metadata.');
if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(metadata.slug) || !/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/.test(metadata.version)) throw new Error('Invalid package identity.');
process.stdout.write(`${metadata.slug} ${metadata.version}\n`);
NODE
)"
read -r slug version <<< "$identity"
dist="$repo/dist"
scratch="$repo/.test-runtime"
for directory in "$dist" "$scratch"; do
  if [[ -L "$directory" || ( -e "$directory" && ! -d "$directory" ) ]]; then
    printf 'Package directories must be real directories, never symbolic links: %s\n' "$directory" >&2
    exit 1
  fi
done
mkdir -p -- "$dist" "$scratch"
stage="$(mktemp -d "$scratch/package-XXXXXXXX")"
cleanup() {
  status=$?
  trap - EXIT
  if [[ -e "$stage" || -L "$stage" ]]; then
    if [[ ! -L "$scratch" && ! -L "$stage" && -d "$scratch" && -d "$stage" ]] &&
      [[ "$(cd -- "$scratch" && pwd -P)" == "$scratch" && "$(cd -- "$stage" && pwd -P)" == "$stage" && "$stage" == "$scratch"/package-* ]]; then
      rm -rf -- "$stage" || { if ((status == 0)); then status=1; fi; }
    else
      printf 'Unsafe staging cleanup path; refusing removal: %s\n' "$stage" >&2
      if ((status == 0)); then status=1; fi
    fi
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
plugin="$stage/$slug"
archive="$stage/package.zip"
manifest="$stage/manifest.json"
bash "$script_dir/release.sh" stage --output "$plugin" --slug "$slug" > "$manifest"
bash "$script_dir/release.sh" verify --output "$plugin" > /dev/null

node - "$manifest" > "$stage/entries.txt" <<'NODE'
const { readFileSync } = require('node:fs');
const manifest = JSON.parse(readFileSync(process.argv[2], 'utf8'));
if (!Array.isArray(manifest.files) || !manifest.files.length) throw new Error('Production manifest is empty.');
const seen = new Set();
for (const file of manifest.files) {
  const name = `${manifest.slug}/${file.path}`;
  if (typeof file.path !== 'string' || /[\\:\x00-\x1f\x7f]/.test(name) || name.split('/').some(part => !part || part === '.' || part === '..')) throw new Error(`Unsafe ZIP entry name: ${JSON.stringify(name)}`);
  if (!/^[a-f0-9]{64}$/.test(file.sha256)) throw new Error('Invalid manifest hash.');
  const key = name.toLowerCase();
  if (seen.has(key)) throw new Error(`Duplicate ZIP entry name: ${name}`);
  seen.add(key);
  process.stdout.write(`${name}\n`);
}
NODE
# No recursive zip: the only input names are the preflighted manifest files.
(cd -- "$stage" && zip -q -X "$archive" -@ < "$stage/entries.txt")
unzip -Z1 "$archive" > "$stage/archive-entries.txt"
node - "$stage/entries.txt" "$stage/archive-entries.txt" <<'NODE'
const { readFileSync } = require('node:fs');
function entries(file) {
  const value = readFileSync(file, 'utf8');
  if (!value.endsWith('\n')) throw new Error('ZIP listing is incomplete.');
  return value.slice(0, -1).split('\n');
}
const expected = entries(process.argv[2]);
const actual = entries(process.argv[3]);
if (new Set(actual.map(name => name.toLowerCase())).size !== actual.length || JSON.stringify(actual.slice().sort()) !== JSON.stringify(expected.slice().sort())) throw new Error('ZIP contains unexpected, noncanonical, duplicate or missing entries.');
NODE
unzip -tqq "$archive"
mkdir -- "$stage/extracted"
# The complete entry set was verified before allowing any extraction.
unzip -qq "$archive" -d "$stage/extracted"
bash "$script_dir/release.sh" verify --output "$stage/extracted/$slug" > "$stage/extracted.json"
node - "$manifest" "$stage/extracted.json" "$archive" "$stage/checksum" <<'NODE'
const { readFileSync, writeFileSync } = require('node:fs');
const { createHash } = require('node:crypto');
const before = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const after = JSON.parse(readFileSync(process.argv[3], 'utf8'));
if (JSON.stringify(before.files) !== JSON.stringify(after.files)) throw new Error('ZIP contents differ from the original staged file hashes.');
const hash = createHash('sha256').update(readFileSync(process.argv[4])).digest('hex');
writeFileSync(process.argv[5], `${hash}  ${before.slug}-${before.version}.zip\n`, { flag: 'wx' });
NODE
# Publish only the verified archive and checksum; never follow output symlinks.
node - "$repo" "$archive" "$stage/checksum" "$slug" "$version" <<'NODE'
const { lstatSync, realpathSync, renameSync, readFileSync } = require('node:fs');
const path = require('node:path');
const [repo, archive, checksum, slug, version] = process.argv.slice(2);
const dist = path.join(repo, 'dist');
if (lstatSync(dist).isSymbolicLink() || !lstatSync(dist).isDirectory() || realpathSync(dist) !== dist) throw new Error('Unsafe package output directory.');
const output = path.join(dist, `${slug}-${version}.zip`);
for (const target of [output, `${output}.sha256`]) {
  try { const info = lstatSync(target); if (info.isSymbolicLink() || !info.isFile()) throw new Error(`Package output must not be a symbolic link or non-file: ${target}`); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
}
const hash = readFileSync(checksum, 'utf8').split(' ')[0];
renameSync(archive, output);
renameSync(checksum, `${output}.sha256`);
process.stdout.write(`${output}\nSHA256 ${hash}\n`);
NODE
