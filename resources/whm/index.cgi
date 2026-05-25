#!/usr/local/cpanel/3rdparty/bin/perl

# WHM admin entry for ZoneMirror.
#
# WHM's chrome (sidebar, banner, breadcrumb) is rendered by
# Whostmgr::HTMLInterface::defheader / deffooter from inside the script
# that handles the request — there is no "embed me in the chrome"
# wrapper at the WHM level. PHP cannot call defheader, so a plain
# index.live.php renders as a naked HTML document with no chrome,
# which is what every PHP plugin in WHM has historically shipped
# (softaculous, backuply, lvemanager all do the same).
#
# This thin Perl CGI fixes that by sitting in front of the PHP:
#   1. Initialise ACLs and reject non-root.
#   2. Print Content-Type + WHM chrome header.
#   3. Embed the existing index.live.php in a same-origin iframe.
#   4. Auto-resize the iframe to its content via ResizeObserver.
#   5. Print the WHM chrome footer.
#
# All admin-tokens logic, CSRF, storage etc. stays in PHP. POST
# submissions inside the iframe target the iframe itself, so the
# host page's WHM session isn't reloaded on every save.

use strict;
use warnings;

BEGIN { unshift @INC, '/usr/local/cpanel', '/usr/local/cpanel/whostmgr/docroot/cgi'; }

use Whostmgr::ACLS          ();
use Whostmgr::HTMLInterface ();

Whostmgr::ACLS::init_acls();

if ( !Whostmgr::ACLS::hasroot() ) {
    print "Content-Type: text/html\r\n\r\n";
    Whostmgr::HTMLInterface::defheader( 'ZoneMirror' );
    print <<'DENY';
<div style="padding:1rem">
  <h2>Permission denied</h2>
  <p>Only the root WHM user can manage ZoneMirror admin tokens.</p>
</div>
DENY
    Whostmgr::HTMLInterface::deffooter();
    exit;
}

my $session = $ENV{'cp_security_token'} // '';
my $php_url = "${session}/cgi/zonemirror/index.live.php";

# Trivially escape the URL for inline JS / src=. cp_security_token is
# a server-issued opaque string (alphanumeric + slash) so this is safe
# without a proper HTML encoder, but be conservative anyway.
$php_url =~ s/"/%22/g;

print "Content-Type: text/html\r\n\r\n";
Whostmgr::HTMLInterface::defheader('ZoneMirror');

print <<"BODY";
<style>
  #zonemirror-frame { width: 100%; min-height: 600px; border: 0; display: block; }
</style>
<iframe id="zonemirror-frame" src="$php_url" title="ZoneMirror admin"></iframe>
<script>
(function () {
  var frame = document.getElementById('zonemirror-frame');
  function fit() {
    try {
      var doc = frame.contentDocument;
      if (!doc || !doc.body) return;
      var h = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight);
      frame.style.height = (h + 40) + 'px';
    } catch (e) { /* cross-origin (shouldn't happen, same host) */ }
  }
  frame.addEventListener('load', function () {
    fit();
    try {
      var ro = new ResizeObserver(fit);
      ro.observe(frame.contentDocument.documentElement);
    } catch (e) { /* ResizeObserver unsupported — fit() on load is enough */ }
  });
  window.addEventListener('resize', fit);
})();
</script>
BODY

Whostmgr::HTMLInterface::deffooter();
