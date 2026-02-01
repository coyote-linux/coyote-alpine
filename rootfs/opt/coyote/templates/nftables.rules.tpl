#!/usr/sbin/nft -f
#
# Coyote Linux 4 - nftables Firewall Ruleset Template
#
# This is a reference template showing the base ruleset structure.
# The actual ruleset is generated programmatically by RulesetBuilder.php
#
# Generated: {{timestamp}}
#

flush ruleset

#
# Filter Table - Main packet filtering
#
table inet filter {

    # === NAMED SETS ===
    # Sets are used for efficient matching of multiple addresses/ports

    # SSH allowed hosts (empty = allow from anywhere if SSH enabled)
    set ssh_allowed {
        type ipv4_addr
        flags interval
        # elements = { 192.168.1.0/24, 10.0.0.0/8 }
    }

    # SNMP allowed hosts
    set snmp_allowed {
        type ipv4_addr
        flags interval
    }

    # Blocked hosts (manual blacklist)
    set blocked_hosts {
        type ipv4_addr
        flags interval
    }

    # === BASE CHAINS ===

    chain input {
        type filter hook input priority 0; policy drop;

        # Connection tracking - accept established, drop invalid
        ct state established,related accept
        ct state invalid drop

        # Loopback is always allowed
        iif lo accept

        # Drop blocked hosts early
        ip saddr @blocked_hosts drop

        # Jump to service-specific ACLs
        jump coyote-local-acls

        # Default: drop with logging
        log prefix "COYOTE-DROP-LOCAL: " level info drop
    }

    chain forward {
        type filter hook forward priority 0; policy drop;

        # Connection tracking
        ct state established,related accept
        ct state invalid drop

        # MSS clamping for PPPoE/VPN (uncomment if needed)
        # tcp flags syn / syn,rst tcp option maxseg size set rt mtu

        # Drop blocked hosts
        ip saddr @blocked_hosts drop
        ip daddr @blocked_hosts drop

        # UPnP dynamic rules (populated by miniupnpd)
        jump igd-forward

        # Port forward ACL chain
        jump auto-forward-acl

        # User-defined ACLs
        jump coyote-user-acls

        # Default: drop with logging
        log prefix "COYOTE-DROP-FWD: " level info drop
    }

    chain output {
        type filter hook output priority 0; policy accept;
    }

    # === SERVICE CHAINS ===

    # Routes incoming traffic to appropriate service chains
    chain coyote-local-acls {
        # SSH access control
        tcp dport 22 jump ssh-hosts

        # SNMP access control
        udp dport 161 jump snmp-hosts

        # ICMP handling
        ip protocol icmp jump icmp-rules
        ip6 nexthdr icmpv6 jump icmp-rules

        # DHCP server access
        udp dport { 67, 68 } jump dhcp-server

        # UPnP local access
        jump igd-input
    }

    # User-defined forwarding ACLs
    chain coyote-user-acls {
        # ACL bindings are inserted here
        # Example: iifname "lan" oifname "wan" jump acl-lan-to-wan
    }

    # SSH access control
    chain ssh-hosts {
        # If set is empty, this accepts all (SSH enabled, no restrictions)
        # If set has entries, only those sources are allowed
        ip saddr @ssh_allowed accept
        # Fallback: accept if set is empty (handled by service config)
        accept
    }

    # SNMP access control
    chain snmp-hosts {
        ip saddr @snmp_allowed accept
        # No fallback - SNMP requires explicit host list
    }

    # ICMP rules
    chain icmp-rules {
        # Rate limiting (optional)
        # ip protocol icmp limit rate 10/second burst 5 packets accept
        # ip protocol icmp drop

        # Allow essential ICMP types
        ip protocol icmp icmp type {
            echo-request,
            echo-reply,
            destination-unreachable,
            time-exceeded,
            parameter-problem
        } accept

        # ICMPv6 - essential for IPv6 operation
        ip6 nexthdr icmpv6 icmpv6 type {
            echo-request,
            echo-reply,
            destination-unreachable,
            packet-too-big,
            time-exceeded,
            parameter-problem,
            nd-neighbor-solicit,
            nd-neighbor-advert,
            nd-router-solicit,
            nd-router-advert
        } accept
    }

    # DHCP server access
    chain dhcp-server {
        # Restrict to specific interface(s)
        # iifname "lan" udp dport { 67, 68 } accept
    }

    # UPnP filter chains (populated by miniupnpd)
    chain igd-forward {
        # Dynamic rules inserted here
    }

    chain igd-input {
        # Dynamic rules inserted here
    }

    # Port forward ACL - allows forwarded traffic
    chain auto-forward-acl {
        # Rules inserted for each port forward
        # Example: tcp dport 80 ip daddr 192.168.1.100 accept
    }

    # === USER ACL CHAINS ===
    # Created dynamically based on configuration
    # Example:
    # chain acl-lan-to-wan {
    #     tcp dport { 80, 443 } accept
    #     udp dport 53 accept
    #     ip protocol icmp accept
    #     return
    # }
}

#
# NAT Table - Network Address Translation
#
table inet nat {

    chain prerouting {
        type nat hook prerouting priority -100;

        # UPnP dynamic NAT (populated by miniupnpd)
        jump igd-preroute

        # Manual port forwards
        jump port-forward

        # Auto/dynamic forwards
        jump auto-forward
    }

    chain postrouting {
        type nat hook postrouting priority 100;

        # NAT bypass rules (skip NAT for specific traffic)
        # Example: ip saddr 192.168.1.0/24 ip daddr 192.168.2.0/24 return

        # Masquerade rules
        # Example: oifname "wan" ip saddr 192.168.1.0/24 masquerade
    }

    # Manual port forwarding
    chain port-forward {
        # Example: iifname "wan" tcp dport 80 dnat to 192.168.1.100:8080
    }

    # Dynamic/auto port forwarding
    chain auto-forward {
        # Populated dynamically
    }

    # UPnP NAT rules
    chain igd-preroute {
        # Populated by miniupnpd
    }
}

#
# Mangle Table - Packet marking for QoS (optional)
#
# table inet mangle {
#     chain forward {
#         type filter hook forward priority -150;
#
#         # Mark packets for QoS classification
#         # tcp dport { 80, 443 } meta mark set 0x10
#         # udp dport 53 meta mark set 0x20
#     }
# }
