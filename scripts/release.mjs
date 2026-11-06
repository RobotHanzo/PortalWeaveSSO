import { appendFileSync, copyFileSync, existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, realpathSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const checkout = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const production = ['wordpress-sso.php', 'uninstall.php', 'README.md', 'readme.txt', 'LICENSE', 'includes', 'assets', 'docs'];
const stableVersion = /^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/;
const runtimeVersion = /^\d+\.\d+(?:\.\d+)?$/;
const packageSlug = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

function field(text, label, source) {
  const escaped = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const values = [...text.matchAll(new RegExp(`^[ \\t]*(?:\\*[ \\t]*)?${escaped}:[ \\t]*([^\\r\\n]*)`, 'gmi'))];
  if (values.length !== 1 || !values[0][1].trim()) throw new Error(`${source}: missing or ambiguous ${label} header.`);
  return values[0][1].trim();
}

function regularFile(file) {
  const info = lstatSync(file);
  if (info.isSymbolicLink()) throw new Error(`Symbolic links cannot be included in a release: ${file}`);
  if (!info.isFile()) throw new Error(`Release file is not a regular file: ${file}`);
  return readFileSync(file, 'utf8');
}

export function validateRelease({ root = checkout, tag } = {}) {
  const source = regularFile(path.join(root, 'wordpress-sso.php'));
  const header = source.match(/^\s*<\?php\s*\/\*\*([\s\S]*?)\*\//)?.[1];
  if (!header) throw new Error('Plugin header is missing from wordpress-sso.php.');
  const readme = regularFile(path.join(root, 'readme.txt'));
  const version = field(header, 'Version', 'wordpress-sso.php');
  if (!stableVersion.test(version)) throw new Error('Plugin version must be a stable X.Y.Z number without leading zeroes.');
  const constants = [...source.matchAll(/\bdefine\s*\(\s*(['"])WPSSO_VERSION\1\s*,\s*(['"])([^'"]+)\2\s*\)\s*;/g)];
  if (constants.length !== 1 || constants[0][3] !== version) throw new Error('WPSSO_VERSION must match the plugin header version exactly.');
  if (field(readme, 'Stable tag', 'readme.txt') !== version) throw new Error('readme.txt Stable tag must match the plugin version, not trunk.');
  if (tag !== undefined && tag !== `v${version}`) throw new Error(`Git tag must be v${version} for this release.`);
  const php = field(header, 'Requires PHP', 'wordpress-sso.php');
  const wordpress = field(header, 'Requires at least', 'wordpress-sso.php');
  for (const [label, value] of [['Requires PHP', php], ['Requires at least', wordpress]]) {
    if (!runtimeVersion.test(value) || field(readme, label, 'readme.txt') !== value) throw new Error(`Minimum runtime ${label} must match between plugin and readme headers.`);
  }
  if (!/^GPL(?:-?v?2(?:\.0)?)(?:-or-later|\s+or\s+later)?$/i.test(field(header, 'License', 'wordpress-sso.php'))
    || !/^GPL(?:-?v?2(?:\.0)?)(?:-or-later|\s+or\s+later)?$/i.test(field(readme, 'License', 'readme.txt'))
    || !/GNU GENERAL PUBLIC LICENSE/.test(regularFile(path.join(root, 'LICENSE')))) {
    throw new Error('The GPL license declarations and distributed LICENSE must be present.');
  }
  if (!new RegExp(`^=[ \\t]+${version.replaceAll('.', '\\.')}[ \\t]+=[ \\t]*$`, 'm').test(readme.replaceAll('\r\n', '\n'))) {
    throw new Error(`readme.txt must document version ${version} in its changelog.`);
  }
  return { version, php, wordpress, slug: 'wordpress-sso' };
}

function forbidden(relative) {
  return relative.split('/').some(part => /^(?:\.env(?:\..*)?|\.git(?:ignore|attributes|hub)?|node_modules|vendor|tests?|fixtures?|coverage|reports?)$/i.test(part))
    || /\.(?:pem|key|p12|pfx|log|bak|sqlite(?:3)?|sql)$/i.test(relative);
}

function collect(directory, prefix = '') {
  const files = [];
  for (const entry of readdirSync(directory, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
    const relative = prefix ? `${prefix}/${entry.name}` : entry.name;
    if (entry.isSymbolicLink()) throw new Error(`Symbolic links cannot be included in a release: ${relative}`);
    if (forbidden(relative)) throw new Error(`Forbidden private or development file in production source: ${relative}`);
    if (entry.isDirectory()) files.push(...collect(path.join(directory, entry.name), relative));
    else if (entry.isFile()) files.push(relative);
    else throw new Error(`Unsupported file type in production source: ${relative}`);
  }
  return files;
}

function productionFiles(root) {
  const files = [];
  for (const relative of production) {
    const source = path.join(root, relative);
    const info = lstatSync(source);
    if (info.isSymbolicLink()) throw new Error(`Symbolic links cannot be included in a release: ${relative}`);
    if (info.isFile()) files.push(relative);
    else if (info.isDirectory()) files.push(...collect(source, relative));
    else throw new Error(`Unsupported production source: ${relative}`);
  }
  return files.sort();
}

function destination(root, output) {
  if (typeof output !== 'string' || !output) throw new Error('A staging output destination is required.');
  const base = realpathSync(root);
  const target = path.resolve(root, output);
  const relative = path.relative(base, target);
  const first = relative.split(path.sep)[0];
  if (!relative || relative.startsWith(`..${path.sep}`) || relative === '..' || path.isAbsolute(relative)) throw new Error('Staging output must be a descendant of the checkout, never outside it.');
  if (production.includes(first) || ['.git', '.github', 'tests', 'scripts', 'node_modules', 'vendor'].includes(first)) throw new Error('Staging destination cannot be inside production source or repository metadata.');
  let current = base;
  for (const part of relative.split(path.sep)) {
    current = path.join(current, part);
    if (existsSync(current)) {
      const info = lstatSync(current);
      if (info.isSymbolicLink()) throw new Error('Staging output cannot contain symbolic-link path components.');
      if (!info.isDirectory()) throw new Error('Staging output path components must be directories.');
    }
  }
  return target;
}

function digest(file) {
  return createHash('sha256').update(readFileSync(file)).digest('hex');
}

export function stageRelease({ root = checkout, output, slug = 'wordpress-sso', tag } = {}) {
  if (typeof slug !== 'string' || slug.length > 100 || !packageSlug.test(slug)) throw new Error('Plugin slug must contain lowercase words/digits separated by single dashes.');
  const metadata = validateRelease({ root, tag });
  const target = destination(root, output);
  if (existsSync(target) && readdirSync(target).length) throw new Error('Staging output already exists and must be empty; existing contents will never be removed.');
  // Preflight the complete source before creating a package that could look valid.
  const files = productionFiles(root);
  mkdirSync(target, { recursive: true });
  for (const relative of files) {
    const copy = path.join(target, relative);
    mkdirSync(path.dirname(copy), { recursive: true });
    copyFileSync(path.join(root, relative), copy);
  }
  return { ...metadata, slug, output: target, files: files.map(file => ({ path: file, sha256: digest(path.join(target, file)) })) };
}

export function verifyStage({ root = checkout, output, tag } = {}) {
  const metadata = validateRelease({ root, tag });
  const target = destination(root, output);
  const expected = productionFiles(root);
  const actual = collect(target).sort();
  if (JSON.stringify(expected) !== JSON.stringify(actual)) throw new Error('Staged files differ from the expected production manifest (unexpected or missing files).');
  for (const relative of expected) {
    if (digest(path.join(root, relative)) !== digest(path.join(target, relative))) throw new Error(`Staged file hash differs from source: ${relative}`);
  }
  return { ...metadata, output: target, files: actual.map(file => ({ path: file, sha256: digest(path.join(target, file)) })) };
}

function options(args) {
  const parsed = {};
  for (let index = 0; index < args.length; index++) {
    const key = args[index];
    if (!['--tag', '--output', '--slug', '--github-output'].includes(key) || parsed[key] !== undefined || args[index + 1] === undefined || args[index + 1].startsWith('--')) throw new Error(`Invalid or missing release option: ${key}`);
    parsed[key] = args[++index];
  }
  return parsed;
}

function main(args) {
  const [command, ...rest] = args;
  const flags = options(rest);
  const shared = { tag: flags['--tag'] };
  let metadata;
  if (command === 'validate') metadata = validateRelease(shared);
  else if (command === 'stage') metadata = stageRelease({ ...shared, output: flags['--output'], ...(flags['--slug'] === undefined ? {} : { slug: flags['--slug'] }) });
  else if (command === 'verify') metadata = verifyStage({ ...shared, output: flags['--output'] });
  else throw new Error('Usage: node scripts/release.mjs validate|stage|verify [--tag vX.Y.Z] [--output DIRECTORY] [--slug APPROVED_SLUG] [--github-output FILE]');
  if (flags['--github-output']) {
    for (const key of ['version', 'php', 'wordpress', 'slug', 'output']) {
      if (metadata[key] !== undefined) {
        if (/[\r\n]/.test(metadata[key])) throw new Error('Release output metadata cannot contain newlines.');
        appendFileSync(flags['--github-output'], `${key}=${metadata[key]}\n`);
      }
    }
  }
  console.log(JSON.stringify(metadata, null, 2));
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try { main(process.argv.slice(2)); }
  catch (error) { console.error(`Release validation failed: ${error.message}`); process.exitCode = 1; }
}
