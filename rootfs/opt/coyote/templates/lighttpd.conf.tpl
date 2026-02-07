# Coyote Linux lighttpd configuration
# Generated from template - do not edit manually

server.modules = (
    "mod_access",
    "mod_accesslog",
    "mod_fastcgi",
    "mod_rewrite",
    "mod_redirect"
)

server.document-root = "/opt/coyote/webadmin/public"
server.errorlog = "/var/log/lighttpd/error.log"
accesslog.filename = "/var/log/lighttpd/access.log"

server.port = 443
ssl.engine = "enable"
ssl.pemfile = "{{ssl.cert}}"
{{#ssl.key}}
ssl.privkey = "{{ssl.key}}"
{{/ssl.key}}
ssl.honor-cipher-order = "enable"
ssl.cipher-list = "EECDH+AESGCM:EDH+AESGCM"
ssl.openssl.ssl-conf-cmd = ("MinProtocol" => "TLSv1.2")

server.username = "lighttpd"
server.groupname = "lighttpd"

# Directory listing disabled
dir-listing.activate = "disable"

# Index files
index-file.names = ("index.php", "index.html")

# MIME types
mimetype.assign = (
    ".html" => "text/html",
    ".css" => "text/css",
    ".js" => "application/javascript",
    ".json" => "application/json",
    ".png" => "image/png",
    ".jpg" => "image/jpeg",
    ".gif" => "image/gif",
    ".svg" => "image/svg+xml",
    ".ico" => "image/x-icon",
    ".txt" => "text/plain"
)

# PHP FastCGI
fastcgi.server = (
    ".php" => ((
        "bin-path" => "/usr/bin/php-cgi83",
        "socket" => "/var/run/lighttpd/php.socket",
        "max-procs" => 2,
        "bin-environment" => (
            "PHP_FCGI_CHILDREN" => "4",
            "PHP_FCGI_MAX_REQUESTS" => "1000"
        )
    ))
)

# URL rewriting for clean URLs
url.rewrite-if-not-file = (
    "^/api/(.*)$" => "/api.php/$1",
    "^/([^?]*)(\?.*)?$" => "/index.php$2"
)

# Deny access to sensitive files
$HTTP["url"] =~ "^/\." {
    url.access-deny = ("")
}

$HTTP["url"] =~ "^/src/" {
    url.access-deny = ("")
}

$HTTP["url"] =~ "^/templates/" {
    url.access-deny = ("")
}
