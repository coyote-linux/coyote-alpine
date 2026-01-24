# Coyote Linux HAProxy configuration
# Generated from template - do not edit manually

global
    log /dev/log local0
    log /dev/log local1 notice
    chroot /var/lib/haproxy
    stats socket /var/run/haproxy.sock mode 660 level admin
    stats timeout 30s
    user haproxy
    group haproxy
    daemon
    ssl-default-bind-ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256
    ssl-default-bind-options ssl-min-ver TLSv1.2

defaults
    log global
    mode {{defaults.mode}}
    option httplog
    option dontlognull
    timeout connect {{defaults.timeout_connect}}
    timeout client {{defaults.timeout_client}}
    timeout server {{defaults.timeout_server}}

{{#stats.enabled}}
frontend stats
    bind *:{{stats.port}}
    mode http
    stats enable
    stats uri {{stats.uri}}
    stats refresh 10s
    {{#stats.auth}}
    stats auth {{stats.auth}}
    {{/stats.auth}}
{{/stats.enabled}}

{{#frontends}}
frontend {{name}}
    bind {{bind}}
    {{#mode}}
    mode {{mode}}
    {{/mode}}
    {{#ssl_cert}}
    bind *:443 ssl crt {{ssl_cert}}
    {{/ssl_cert}}
    {{#acls}}
    acl {{acl_name}} {{acl_condition}}
    {{/acls}}
    {{#use_backend}}
    use_backend {{backend}} if {{condition}}
    {{/use_backend}}
    default_backend {{default_backend}}
{{/frontends}}

{{#backends}}
backend {{name}}
    {{#mode}}
    mode {{mode}}
    {{/mode}}
    balance {{balance}}
    {{#health_check}}
    option httpchk {{health_check_path}}
    {{/health_check}}
    {{#cookie}}
    cookie {{cookie}} insert indirect nocache
    {{/cookie}}
    {{#servers}}
    server {{server_name}} {{address}}:{{port}} weight {{weight}} check{{#backup}} backup{{/backup}}
    {{/servers}}
{{/backends}}
