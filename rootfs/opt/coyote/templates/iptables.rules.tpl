# Coyote Linux iptables rules
# Generated from template - do not edit manually

*filter
:INPUT {{default_policy}} [0:0]
:FORWARD {{default_policy}} [0:0]
:OUTPUT ACCEPT [0:0]

# Allow loopback
-A INPUT -i lo -j ACCEPT
-A OUTPUT -o lo -j ACCEPT

# Allow established and related
-A INPUT -m state --state ESTABLISHED,RELATED -j ACCEPT
-A FORWARD -m state --state ESTABLISHED,RELATED -j ACCEPT

# Allow ICMP ping
-A INPUT -p icmp --icmp-type echo-request -j ACCEPT

{{#rules}}
# {{comment}}
-A {{chain}} {{#protocol}}-p {{protocol}} {{/protocol}}{{#source}}-s {{source}} {{/source}}{{#destination}}-d {{destination}} {{/destination}}{{#interface}}-i {{interface}} {{/interface}}{{#port}}--dport {{port}} {{/port}}-j {{action}}
{{/rules}}

# Log dropped packets
-A INPUT -j LOG --log-prefix "IPT-DROP-IN: " --log-level 4
-A FORWARD -j LOG --log-prefix "IPT-DROP-FWD: " --log-level 4

COMMIT

*nat
:PREROUTING ACCEPT [0:0]
:INPUT ACCEPT [0:0]
:OUTPUT ACCEPT [0:0]
:POSTROUTING ACCEPT [0:0]

{{#nat}}
# Masquerade for {{interface}}
-A POSTROUTING {{#source}}-s {{source}} {{/source}}-o {{interface}} -j MASQUERADE
{{/nat}}

{{#port_forwards}}
# Port forward: {{description}}
-A PREROUTING {{#interface}}-i {{interface}} {{/interface}}-p {{protocol}} --dport {{port}} -j DNAT --to-destination {{to_address}}:{{to_port}}
{{/port_forwards}}

COMMIT
