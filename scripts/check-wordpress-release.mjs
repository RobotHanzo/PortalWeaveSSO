import { pathToFileURL } from 'node:url';

export async function checkWordPressRelease({ slug, version, request = fetch }) {
  if (typeof slug !== 'string' || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
    throw new Error('WORDPRESS_PLUGIN_SLUG must be the assigned WordPress.org directory slug.');
  }
  if (typeof version !== 'string' || !/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/.test(version)) {
    throw new Error('WordPress releases require a stable X.Y.Z version.');
  }
  const options = { redirect: 'error', signal: AbortSignal.timeout(20000), headers: { 'User-Agent': 'PortalWeave-SSO-release-preflight/1.0' } };
  const base = `https://plugins.svn.wordpress.org/${slug}/`;
  const repository = await request(base, options);
  if (repository.status !== 200) {
    throw new Error(`The assigned WordPress.org SVN repository is unavailable (HTTP ${repository.status}). Initial directory approval is required.`);
  }
  const tag = await request(`${base}tags/${version}/`, options);
  if (tag.status === 200) {
    throw new Error(`WordPress.org version ${version} already exists. Published versions must not be overwritten.`);
  }
  if (tag.status !== 404) {
    throw new Error(`Could not establish that the release tag is unused (HTTP ${tag.status}).`);
  }
  return { slug, version, repository: base };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const args = process.argv.slice(2);
    if (args.length !== 4 || args[0] !== '--slug' || args[2] !== '--version') {
      throw new Error('Usage: node scripts/check-wordpress-release.mjs --slug SLUG --version X.Y.Z');
    }
    console.log(JSON.stringify(await checkWordPressRelease({ slug: args[1], version: args[3] })));
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
