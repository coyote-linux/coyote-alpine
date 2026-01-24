# Coyote Linux dnsmasq configuration
# Generated from template - do not edit manually

# Interface binding
{{#interfaces}}
interface={{name}}
{{/interfaces}}

# Don't read /etc/resolv.conf
no-resolv

# Upstream DNS servers
{{#forwarders}}
server={{address}}
{{/forwarders}}

# Domain
{{#domain}}
domain={{domain}}
local=/{{domain}}/
{{/domain}}

# DHCP Settings
{{#dhcp.enabled}}
# Enable DHCP on {{dhcp.interface}}
dhcp-range={{dhcp.interface}},{{dhcp.range_start}},{{dhcp.range_end}},{{dhcp.lease_time}}s

{{#dhcp.gateway}}
dhcp-option={{dhcp.interface}},option:router,{{dhcp.gateway}}
{{/dhcp.gateway}}

{{#dhcp.dns}}
dhcp-option={{dhcp.interface}},option:dns-server,{{dhcp.dns}}
{{/dhcp.dns}}

# Static leases
{{#dhcp.static_leases}}
dhcp-host={{mac}},{{ip}},{{name}}
{{/dhcp.static_leases}}
{{/dhcp.enabled}}

# DNS caching
cache-size=1000

# Logging
log-queries
log-dhcp
log-facility=/var/log/dnsmasq.log
