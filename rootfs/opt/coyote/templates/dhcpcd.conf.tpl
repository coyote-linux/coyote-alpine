# Coyote Linux dhcpcd configuration
# Generated from template - do not edit manually

# Inform the DHCP server of our hostname
hostname

# Use the same DUID + IAID as set in DHCPv6 for DHCPv4 ClientID
duid

# Persist interface configuration when dhcpcd exits
persistent

# Rapid commit support
option rapid_commit

# A list of options to request from the DHCP server
option domain_name_servers, domain_name, domain_search, host_name
option classless_static_routes
option interface_mtu

# Respect the network MTU
require dhcp_server_identifier

# Disable link-local addresses
noipv4ll

{{#interfaces}}
{{#dhcp}}
# Interface {{name}} - DHCP
interface {{name}}
{{#metric}}
metric {{metric}}
{{/metric}}
{{/dhcp}}
{{#static}}
# Interface {{name}} - Static
interface {{name}}
static ip_address={{address}}
{{#gateway}}
static routers={{gateway}}
{{/gateway}}
{{#dns}}
static domain_name_servers={{dns}}
{{/dns}}
{{/static}}
{{/interfaces}}
