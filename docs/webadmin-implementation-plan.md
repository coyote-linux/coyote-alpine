# Coyote Linux 4 Web Admin Implementation Plan

## Executive Summary

This document outlines the plan to implement full configuration editing capabilities in the Coyote Linux 4 web admin interface. The goal is to bring forward all functionality from Coyote 3 while adding new features for v4.

## Current State Analysis

### Coyote 4 Web Admin (What's Working)
- **Dashboard**: System hardware info, memory/CPU, service status, MRTG graphs
- **Authentication**: Session-based auth (currently bypassed for development)
- **Configuration Apply Mechanism**: 60-second rollback protection implemented
- **API Framework**: Router, JSON responses, status endpoints
- **UI Framework**: Clean dark theme, responsive layout, vanilla JS

### Coyote 4 Web Admin (Placeholder Only)
- Network interface editing
- Firewall rules management
- NAT/port forwarding configuration
- VPN tunnel configuration
- Load balancer configuration
- Service start/stop actions
- System settings save
- Firmware upload
- Backup/restore

### Coyote 3 Features to Bring Forward
1. **System**: Backup/restore config, firmware upgrade, reboot
2. **General Settings**: Passwords, logging, DHCP server, SNMP, remote admin, UPnP, Dynamic DNS
3. **Interfaces**: Full interface editing (static/DHCP/PPPoE), VLAN, bridge, multiple IPs, MTU
4. **Network**: Static routes, NAT rules, port forwards, proxy ARP, QoS
5. **Firewall**: Access Control Lists, firewall rules, ICMP control
6. **Statistics**: Interface stats, traffic, logs, processes, routing, DHCP leases, connections

---

## Implementation Phases

### Phase 1: Core Infrastructure (Foundation)
**Priority: Critical | Estimated Effort: Medium**

Before implementing features, ensure the core infrastructure is solid.

#### 1.1 Configuration Service Layer
Create PHP service classes that handle configuration CRUD operations:

```
lib/Coyote/Config/
├── ConfigManager.php      (exists - enhance)
├── NetworkConfig.php      (new)
├── FirewallConfig.php     (new)
├── NatConfig.php          (new)
├── DhcpConfig.php         (new)
├── SystemConfig.php       (new)
└── ValidationService.php  (new)
```

**Tasks:**
- [ ] Create `ValidationService.php` with IP, CIDR, hostname, domain, port, MAC validators
- [ ] Create section-specific config classes with getters/setters
- [ ] Add dirty flag tracking per section (like Coyote 3)
- [ ] Implement working config vs running config pattern
- [ ] Add config schema validation

#### 1.2 Form Component Library
Create reusable form components for the web UI:

**Tasks:**
- [ ] Create `FormHelper.php` class with methods for generating form elements
- [ ] Add client-side validation JavaScript
- [ ] Create confirmation dialog component
- [ ] Create inline error display component
- [ ] Add CSRF token protection

#### 1.3 API Standardization
Standardize API responses and error handling:

**Tasks:**
- [ ] Define standard API response format: `{success: bool, data: {}, errors: []}`
- [ ] Create API error codes and messages
- [ ] Add request validation middleware
- [ ] Document API endpoints

---

### Phase 2: System Management
**Priority: High | Estimated Effort: Medium**

#### 2.1 System Settings (General)
**File: SystemController.php, system.php template**

**Features:**
- [x] Hostname, domain, timezone display (exists)
- [ ] Hostname, domain, timezone editing with save
- [ ] Time server (NTP) configuration
- [ ] DNS server configuration (nameservers)

**Coyote 3 Reference:** `general_settings.php`

#### 2.2 User Management / Passwords
**New File: UsersController.php**

**Features:**
- [ ] Change admin password
- [ ] Password validation (minimum 6 chars, confirmation)
- [ ] Add/remove additional admin accounts
- [ ] SSH authorized keys management

**Coyote 3 Reference:** `passwords.php`

#### 2.3 Backup and Restore
**New File: BackupController.php**

**Features:**
- [ ] Export configuration as JSON download
- [ ] Import configuration from uploaded JSON
- [ ] Configuration versioning (keep last N backups)
- [ ] Warn about sensitive data in backups

**Coyote 3 Reference:** `backup.php`

#### 2.4 Firmware Management
**File: FirmwareController.php (enhance)**

**Features:**
- [x] Show current firmware info (exists)
- [ ] Upload new firmware image
- [ ] Verify firmware signature before install
- [ ] Show previous firmware (rollback option)
- [ ] Trigger firmware upgrade with confirmation

**Coyote 3 Reference:** `sysupgrade.php`

#### 2.5 Reboot / Shutdown
**File: SystemController.php (enhance)**

**Features:**
- [ ] Reboot with confirmation dialog
- [ ] Shutdown with confirmation dialog
- [ ] Show reboot countdown/progress

**Coyote 3 Reference:** `reboot.php`

---

### Phase 3: Network Configuration
**Priority: High | Estimated Effort: High**

#### 3.1 Interface Management
**File: NetworkController.php (enhance), new templates**

**Features:**
- [x] List interfaces with status (exists)
- [ ] Edit interface configuration:
  - Static IP with CIDR
  - DHCP client mode
  - PPPoE mode (username/password)
  - Multiple IP addresses per interface
  - MTU setting
  - MAC address override
- [ ] Interface enable/disable
- [ ] Interface rename (logical name)
- [ ] VLAN configuration (802.1q tagging)

**Coyote 3 Reference:** `interface_settings.php`, `edit_interface.php`

#### 3.2 Static Routes
**New section in NetworkController or separate RoutingController**

**Features:**
- [ ] List current routes
- [ ] Add static route (destination CIDR, gateway, metric, interface)
- [ ] Edit/delete routes
- [ ] Route validation (gateway reachable)

**Coyote 3 Reference:** `routing.php`

#### 3.3 Bridge Configuration
**New File: BridgeController.php (or NetworkController section)**

**Features:**
- [ ] Create bridge interfaces
- [ ] Add/remove interfaces to bridge
- [ ] Spanning tree settings
- [ ] Bridge priority, aging time

**Coyote 3 Reference:** `bridging.php`

---

### Phase 4: Firewall Configuration
**Priority: High | Estimated Effort: High**

#### 4.1 Firewall Rules (ACLs)
**File: FirewallController.php (implement)**

**Features:**
- [ ] List Access Control Lists
- [ ] Create/edit/delete ACLs
- [ ] Reorder ACLs (priority)
- [ ] Within each ACL:
  - List rules
  - Add/edit/delete rules
  - Reorder rules
- [ ] Rule properties:
  - Source/destination IP/CIDR
  - Source/destination port(s)
  - Protocol (tcp, udp, icmp, all)
  - Action (accept, drop, reject)
  - Logging option
  - Comment/description

**Coyote 3 Reference:** `firewall_rules.php`, `access_list.php`, `add_rule.php`, `edit_rule.php`

#### 4.2 ICMP Control
**File: FirewallController.php section**

**Features:**
- [ ] Enable/disable ICMP types per interface
- [ ] Common presets (allow ping, block all, etc.)

**Coyote 3 Reference:** `icmp_rules.php`

---

### Phase 5: NAT and Port Forwarding
**Priority: High | Estimated Effort: Medium**

#### 5.1 NAT Rules
**File: NatController.php (implement)**

**Features:**
- [ ] List NAT rules (source NAT / masquerade)
- [ ] Add NAT rule:
  - Source network (CIDR)
  - Outbound interface
  - NAT to address (or masquerade)
  - Bypass option (exclude from NAT)
- [ ] Edit/delete NAT rules

**Coyote 3 Reference:** `nat.php`

#### 5.2 Port Forwarding (DNAT)
**File: NatController.php section**

**Features:**
- [ ] List port forwards
- [ ] Add port forward:
  - External port(s)
  - Internal IP address
  - Internal port
  - Protocol (tcp/udp/both)
  - Source IP restriction (optional)
- [ ] Edit/delete port forwards

**Coyote 3 Reference:** `portforwards.php`

---

### Phase 6: Services Configuration
**Priority: Medium | Estimated Effort: Medium**

#### 6.1 DHCP Server
**New File: DhcpController.php**

**Features:**
- [ ] Enable/disable DHCP per interface
- [ ] DHCP pool configuration:
  - Start/end IP
  - Lease time
  - DNS servers to distribute
  - Gateway to distribute
  - Domain name
- [ ] Static reservations (MAC to IP)
- [ ] View active leases

**Coyote 3 Reference:** `dhcpd.php`, `dhcpreservations.php`

#### 6.2 DNS Server (dnsmasq)
**File: DhcpController.php or separate DnsController.php**

**Features:**
- [ ] Local DNS entries
- [ ] Upstream DNS servers
- [ ] DNS caching settings

#### 6.3 SSH Server
**File: ServicesController.php section**

**Features:**
- [ ] Enable/disable SSH
- [ ] SSH port configuration
- [ ] Access restrictions (allowed hosts/networks)

**Coyote 3 Reference:** `remoteconf.php`

#### 6.4 SNMP Service
**New File: SnmpController.php**

**Features:**
- [ ] Enable/disable SNMP
- [ ] Community string
- [ ] Contact/location info
- [ ] Access restrictions

**Coyote 3 Reference:** `snmpd.php`

#### 6.5 Logging Configuration
**File: SystemController.php section or LoggingController.php**

**Features:**
- [ ] Local logging level
- [ ] Remote syslog server
- [ ] Log firewall accepts/denies

**Coyote 3 Reference:** `logging.php`

---

### Phase 7: VPN Configuration (New for v4)
**Priority: Medium | Estimated Effort: High**

#### 7.1 IPSec VPN (StrongSwan)
**File: VpnController.php (implement)**

**Features:**
- [ ] List IPSec tunnels with status
- [ ] Create site-to-site tunnel:
  - Remote gateway IP
  - Local/remote subnets
  - Authentication (PSK or certificates)
  - IKE version (v1/v2)
  - Encryption settings
- [ ] Start/stop individual tunnels
- [ ] View tunnel statistics

#### 7.2 WireGuard VPN (New for v4)
**File: VpnController.php section**

**Features:**
- [ ] Create WireGuard interface
- [ ] Add peers (public key, allowed IPs, endpoint)
- [ ] Generate key pairs
- [ ] Show QR codes for mobile config

#### 7.3 OpenVPN (New for v4)
**File: VpnController.php section**

**Features:**
- [ ] Server mode configuration
- [ ] Client configuration export

---

### Phase 8: Load Balancer Configuration (New for v4)
**Priority: Medium | Estimated Effort: Medium**

#### 8.1 HAProxy Management
**File: LoadBalancerController.php (implement)**

**Features:**
- [ ] List frontends with status
- [ ] Create/edit frontend:
  - Bind address/port
  - Mode (HTTP/TCP)
  - Default backend
  - SSL settings
- [ ] List backends
- [ ] Create/edit backend:
  - Load balancing algorithm
  - Health check settings
  - Server list (address, port, weight)
- [ ] View real-time statistics
- [ ] Reload HAProxy without downtime

---

### Phase 9: Monitoring and Statistics
**Priority: Low | Estimated Effort: Medium**

#### 9.1 Enhanced Statistics Page
**File: StatsController.php (new)**

**Features:**
- [ ] Network interface statistics (packets, bytes, errors)
- [ ] Real-time traffic graphs (enhance MRTG integration)
- [ ] Connection tracking table
- [ ] Routing table display
- [ ] ARP table display
- [ ] Active DHCP leases

**Coyote 3 Reference:** `statistics.php`

#### 9.2 Log Viewer
**File: DebugController.php (enhance)**

**Features:**
- [x] View system logs (basic exists)
- [ ] Firewall log with filtering
- [ ] Log search functionality
- [ ] Log download/export

#### 9.3 System Processes
**Features:**
- [ ] Running process list
- [ ] Service resource usage

---

### Phase 10: Quality of Service (Advanced)
**Priority: Low | Estimated Effort: High**

#### 10.1 Traffic Shaping
**New File: QosController.php**

**Features:**
- [ ] Enable/disable QoS per interface
- [ ] Bandwidth limits (upload/download)
- [ ] Traffic classification rules
- [ ] Priority queues

**Coyote 3 Reference:** `edit_qos.php`, `edit_qosrule.php`

---

### Phase 11: Additional Services (Advanced)
**Priority: Low | Estimated Effort: Medium**

#### 11.1 UPnP Service
**Features:**
- [ ] Enable/disable UPnP/NAT-PMP
- [ ] Allowed interfaces
- [ ] View active UPnP mappings

**Coyote 3 Reference:** `upnp.php`

#### 11.2 Dynamic DNS
**Features:**
- [ ] Configure DDNS provider
- [ ] Hostname, username, password
- [ ] Update interval

**Coyote 3 Reference:** `ddns.php`

#### 11.3 Proxy ARP
**Features:**
- [ ] Add proxy ARP entries

**Coyote 3 Reference:** `proxyarp.php`

---

## Technical Architecture

### Configuration Flow

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   Web UI Form   │────▶│  Controller      │────▶│  Config Service │
│   (templates)   │     │  (validation)    │     │  (CRUD ops)     │
└─────────────────┘     └──────────────────┘     └────────┬────────┘
                                                          │
┌─────────────────┐     ┌──────────────────┐     ┌────────▼────────┐
│  Apply Script   │◀────│  Apply Service   │◀────│  Working Config │
│ (apply-config)  │     │  (60s rollback)  │     │  (/tmp/...)     │
└────────┬────────┘     └──────────────────┘     └─────────────────┘
         │
         ▼
┌─────────────────┐
│  System State   │
│  (iptables,     │
│   networking,   │
│   services)     │
└─────────────────┘
```

### JSON Configuration Schema

Extend `/mnt/config/system.json` with additional sections:

```json
{
  "version": "4.0.0",
  "system": {
    "hostname": "coyote",
    "domain": "local.lan",
    "timezone": "America/New_York",
    "timeserver": "pool.ntp.org",
    "nameservers": ["1.1.1.1", "8.8.8.8"]
  },
  "users": [
    {"username": "admin", "password_hash": "..."}
  ],
  "network": {
    "interfaces": [...],
    "routes": [...],
    "bridges": [...]
  },
  "firewall": {
    "enabled": true,
    "default_policy": "drop",
    "acls": [
      {
        "name": "lan-to-wan",
        "interface_in": "lan",
        "interface_out": "wan",
        "rules": [...]
      }
    ],
    "icmp": {...}
  },
  "nat": {
    "masquerade": [...],
    "port_forwards": [...]
  },
  "services": {
    "dhcpd": {...},
    "ssh": {...},
    "snmp": {...},
    "logging": {...},
    "upnp": {...},
    "ddns": {...}
  },
  "vpn": {
    "ipsec": {...},
    "wireguard": {...},
    "openvpn": {...}
  },
  "loadbalancer": {
    "frontends": [...],
    "backends": [...]
  },
  "qos": {...}
}
```

### Validation Rules

Create comprehensive validation for all input types:

| Type | Validation | Example |
|------|------------|---------|
| IP Address | IPv4 dotted decimal | 192.168.1.1 |
| CIDR | IP/prefix length | 192.168.1.0/24 |
| Port | 1-65535 | 443 |
| Port Range | port-port | 8000-8080 |
| Hostname | RFC 1123 | my-router |
| Domain | RFC 1035 | example.com |
| MAC | 6 hex pairs | aa:bb:cc:dd:ee:ff |
| Protocol | tcp/udp/icmp/all | tcp |

---

## UI/UX Guidelines

### Form Patterns

1. **Inline Validation**: Show errors next to fields immediately
2. **Confirmation Dialogs**: For all destructive actions
3. **Progress Indicators**: For long-running operations
4. **Success Messages**: Auto-dismiss after 5 seconds
5. **Unsaved Changes Warning**: Prompt before navigating away

### Table Patterns

1. **Action Buttons**: Edit, Delete, Up/Down for each row
2. **Status Indicators**: Color-coded badges
3. **Empty States**: Helpful message when no items exist
4. **Sortable Columns**: Where applicable

### Mobile Responsiveness

- Sidebar collapses on small screens
- Tables scroll horizontally
- Forms stack vertically

---

## Migration from Coyote 3

### Config Migration Tool

Create a tool to import Coyote 3 serialized config into v4 JSON format:

```bash
/opt/coyote/bin/migrate-config /path/to/v3/sysconfig /mnt/config/system.json
```

### Feature Parity Checklist

| Coyote 3 Feature | Coyote 4 Status | Priority |
|------------------|-----------------|----------|
| General Settings | Phase 2 | High |
| Passwords | Phase 2 | High |
| Backup/Restore | Phase 2 | High |
| Interface Edit | Phase 3 | High |
| Static Routes | Phase 3 | High |
| Firewall Rules | Phase 4 | High |
| NAT | Phase 5 | High |
| Port Forwards | Phase 5 | High |
| DHCP Server | Phase 6 | Medium |
| SNMP | Phase 6 | Low |
| Logging | Phase 6 | Medium |
| QoS | Phase 10 | Low |
| UPnP | Phase 11 | Low |
| Dynamic DNS | Phase 11 | Low |
| Proxy ARP | Phase 11 | Low |

---

## Development Priorities

### Recommended Order

1. **Phase 1**: Core infrastructure (required for everything)
2. **Phase 2**: System management (users need this immediately)
3. **Phase 3**: Network configuration (core functionality)
4. **Phase 5**: NAT and port forwards (most common use case)
5. **Phase 4**: Firewall rules (security critical)
6. **Phase 6**: Services (DHCP especially)
7. **Phase 7-8**: VPN and Load Balancer (advanced features)
8. **Phase 9-11**: Monitoring, QoS, extras

### Quick Wins

Start with these for immediate impact:
1. System settings save functionality
2. Service start/stop buttons
3. Reboot button
4. Backup/restore

---

## Testing Strategy

### Unit Tests
- Validation functions
- Config CRUD operations
- API endpoint responses

### Integration Tests
- Form submission flows
- Configuration apply/rollback
- Service restart verification

### Manual Testing
- Cross-browser testing
- Mobile responsiveness
- Error condition handling

---

## Timeline Estimate

| Phase | Effort | Dependencies |
|-------|--------|--------------|
| Phase 1 | 2-3 days | None |
| Phase 2 | 3-4 days | Phase 1 |
| Phase 3 | 4-5 days | Phase 1 |
| Phase 4 | 4-5 days | Phase 1 |
| Phase 5 | 2-3 days | Phase 1 |
| Phase 6 | 3-4 days | Phase 1 |
| Phase 7 | 5-7 days | Phase 1, 3 |
| Phase 8 | 3-4 days | Phase 1 |
| Phase 9 | 2-3 days | Phase 1 |
| Phase 10 | 4-5 days | Phase 1, 3 |
| Phase 11 | 2-3 days | Phase 1 |

**Total: ~35-45 days of development effort**

---

## Next Steps

1. Review and approve this implementation plan
2. Decide on phase priorities based on user needs
3. Begin Phase 1 infrastructure work
4. Iterate through phases based on priority
