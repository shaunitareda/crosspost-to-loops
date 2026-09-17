#!/usr/bin/env bash
set -euo pipefail

python3 <<'PY'
from pathlib import Path


def replace_once(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected 1 match, found {count}")
    return text.replace(old, new, 1)


path = Path('crosspost-to-loops.php')
text = path.read_text()
text = replace_once(text, " * Version:           1.0.2", " * Version:           1.0.3", 'plugin header version')
text = replace_once(text, "define( 'CTL_VERSION', '1.0.2' );", "define( 'CTL_VERSION', '1.0.3' );", 'version constant')

old = "\t\t\t\t\t\t$crossposted = get_post_meta( $p->ID, self::META_VIDEO_ID, true ) ? ' ✓' : '';"
new = """\t\t\t\t\t\t$crossposted = get_post_meta( $p->ID, self::META_VIDEO_ID, true ) ? ' ✓' : '';
\t\t\t\t\t\t$label       = trim( $p->post_title );

\t\t\t\t\t\tif ( '' === $label ) {
\t\t\t\t\t\t\t$content = wp_strip_all_tags( strip_shortcodes( $p->post_content ), true );
\t\t\t\t\t\t\t$content = trim( preg_replace( '/\\s+/u', ' ', $content ) ?? '' );

\t\t\t\t\t\t\tif ( '' !== $content ) {
\t\t\t\t\t\t\t\t$label = wp_html_excerpt( $content, 80, '…' );
\t\t\t\t\t\t\t} else {
\t\t\t\t\t\t\t\t$post_type_object = get_post_type_object( $p->post_type );
\t\t\t\t\t\t\t\t$singular_label   = ! empty( $post_type_object->labels->singular_name ) ? $post_type_object->labels->singular_name : __( 'Post', 'crosspost-to-loops' );
\t\t\t\t\t\t\t\t/* translators: 1: post type singular label, 2: post ID. */
\t\t\t\t\t\t\t\t$label = sprintf( __( 'Untitled %1$s (#%2$d)', 'crosspost-to-loops' ), $singular_label, $p->ID );
\t\t\t\t\t\t\t}
\t\t\t\t\t\t}"""
text = replace_once(text, old, new, 'Test Crosspost label setup')
text = replace_once(
    text,
    "<?php echo esc_html( $p->post_title . $crossposted ); ?>",
    "<?php echo esc_html( $label . $crossposted ); ?>",
    'Test Crosspost option output',
)
path.write_text(text)

readme = Path('README.md')
text = readme.read_text()
text = replace_once(text, '**Stable Tag:** 1.0.2', '**Stable Tag:** 1.0.3', 'README stable tag')
marker = '## Changelog\n\n'
entry = "### 1.0.3\n- Fixed blank Test Crosspost selector rows by showing concise content snippets for titleless posts, with an untitled post-type fallback when no useful text exists.\n\n"
text = replace_once(text, marker, marker + entry, 'README changelog')
readme.write_text(text)

wp_readme = Path('readme.txt')
text = wp_readme.read_text()
text = replace_once(text, 'Stable tag:        1.0.2', 'Stable tag:        1.0.3', 'WordPress readme stable tag')
marker = '== Changelog ==\n\n'
entry = "= 1.0.3 =\n* Fixed blank Test Crosspost selector rows by showing concise content snippets for titleless posts, with an untitled post-type fallback when no useful text exists.\n\n"
text = replace_once(text, marker, marker + entry, 'WordPress readme changelog')
wp_readme.write_text(text)
PY

php -l crosspost-to-loops.php
git diff --check

test "$(grep -F "'posts_per_page' => 50" crosspost-to-loops.php | wc -l)" -eq 1
grep -Fq "wp_html_excerpt( \$content, 80, '…' )" crosspost-to-loops.php
grep -Fq "Untitled %1\$s (#%2\$d)" crosspost-to-loops.php
grep -Fq "\$label . \$crossposted" crosspost-to-loops.php

changed="$(git diff --name-only | sort | paste -sd ' ' -)"
test "$changed" = "README.md crosspost-to-loops.php readme.txt"

git diff --unified=0 -- crosspost-to-loops.php README.md readme.txt > /tmp/ctl-ui.diff
if grep -iE '^[+-].*(Rabbit_Cast|RCTL_|X-Eboni-Rabbit-Key|rabbit-cast)' /tmp/ctl-ui.diff; then
  echo 'Rabbit-specific change detected unexpectedly.' >&2
  exit 1
fi
if grep -iE '^[+-].*(OAUTH|/oauth/|/api/v1/apps|/api/v1/studio/upload|studio/upload|resolve_video|detect_video|upload_video)' /tmp/ctl-ui.diff; then
  echo 'OAuth/upload/video-resolution change detected unexpectedly.' >&2
  exit 1
fi

mkdir -p /tmp/ctl-qa /tmp/ctl-baseline
git show 7cb7739e5514e263f4231f8e3fa6e088380b10af:crosspost-to-loops.php > /tmp/ctl-baseline/crosspost-to-loops.php
cd /tmp/ctl-qa
composer init --no-interaction --name=ctl/qa >/dev/null
composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer require --no-interaction --no-progress squizlabs/php_codesniffer:^3.10 wp-coding-standards/wpcs:3.4.1 dealerdirect/phpcodesniffer-composer-installer:^1.0

set +e
vendor/bin/phpcs --standard=WordPress --extensions=php --report=json /tmp/ctl-baseline/crosspost-to-loops.php > /tmp/ctl-baseline.json
vendor/bin/phpcs --standard=WordPress --extensions=php --report=json "$GITHUB_WORKSPACE/crosspost-to-loops.php" > /tmp/ctl-patched.json
set -e

python3 <<'PY'
import json
from pathlib import Path

baseline = json.loads(Path('/tmp/ctl-baseline.json').read_text())['totals']
patched = json.loads(Path('/tmp/ctl-patched.json').read_text())['totals']
print(f"WPCS baseline: errors={baseline['errors']} warnings={baseline['warnings']}")
print(f"WPCS patched:  errors={patched['errors']} warnings={patched['warnings']}")
if baseline['errors'] != 16 or baseline['warnings'] != 0:
    raise SystemExit('Unexpected v1.0.2 WPCS baseline; expected 16 errors / 0 warnings.')
if patched['errors'] > baseline['errors'] or patched['warnings'] > baseline['warnings']:
    raise SystemExit('v1.0.3 introduced new WPCS violations.')
PY

cd "$GITHUB_WORKSPACE"
rm -f .github/workflows/test-crosspost-dropdown-1-0-3.yml
rm -f .github/scripts/fix-test-crosspost-dropdown-1-0-3.sh
rmdir .github/workflows 2>/dev/null || true
rmdir .github/scripts 2>/dev/null || true
rmdir .github 2>/dev/null || true

git diff --check
git status --short
git diff --stat

git config user.name 'github-actions[bot]'
git config user.email '41898282+github-actions[bot]@users.noreply.github.com'
git add -A
git commit -m 'Fix Test Crosspost selector labels for v1.0.3'
git push origin HEAD:fix-test-crosspost-dropdown-1.0.3
