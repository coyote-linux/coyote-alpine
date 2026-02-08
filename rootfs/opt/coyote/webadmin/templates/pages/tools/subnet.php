<p><a href="/tools">&larr; Back to Tools</a></p>

<div class="dashboard-grid">
    <div class="card">
        <h3>IPv4 Subnet Calculator</h3>
        <div class="form-group">
            <label for="ipv4-input">IP Address / CIDR</label>
            <input type="text" id="ipv4-input" placeholder="192.168.1.100/24">
            <small class="text-muted">Enter an IPv4 address with prefix length (e.g. 192.168.1.100/24)</small>
        </div>
        <button class="btn btn-primary" id="ipv4-calc">Calculate</button>
        <div id="ipv4-result" class="tool-result" style="display:none;">
            <dl>
                <dt>Network Address</dt><dd id="v4-network"></dd>
                <dt>Broadcast Address</dt><dd id="v4-broadcast"></dd>
                <dt>Subnet Mask</dt><dd id="v4-netmask"></dd>
                <dt>Wildcard Mask</dt><dd id="v4-wildcard"></dd>
                <dt>CIDR Notation</dt><dd id="v4-cidr"></dd>
                <dt>IP Class</dt><dd id="v4-class"></dd>
                <dt>First Usable Host</dt><dd id="v4-first"></dd>
                <dt>Last Usable Host</dt><dd id="v4-last"></dd>
                <dt>Total Usable Hosts</dt><dd id="v4-hosts"></dd>
                <dt>Binary Subnet Mask</dt><dd id="v4-binary" class="password-text"></dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <h3>IPv6 Subnet Calculator</h3>
        <div class="form-group">
            <label for="ipv6-input">IPv6 Address / Prefix</label>
            <input type="text" id="ipv6-input" placeholder="2001:db8::1/48">
            <small class="text-muted">Enter an IPv6 address with prefix length (e.g. 2001:db8::1/48)</small>
        </div>
        <button class="btn btn-primary" id="ipv6-calc">Calculate</button>
        <div id="ipv6-result" class="tool-result" style="display:none;">
            <dl>
                <dt>Full Address</dt><dd id="v6-full"></dd>
                <dt>Prefix Length</dt><dd id="v6-prefix"></dd>
                <dt>Network Prefix</dt><dd id="v6-network"></dd>
                <dt>First Address</dt><dd id="v6-first"></dd>
                <dt>Last Address</dt><dd id="v6-last"></dd>
                <dt>Total Addresses</dt><dd id="v6-total"></dd>
            </dl>
        </div>
    </div>
</div>

<script>
(function() {
    function parseIPv4(str) {
        var parts = str.trim().split('/');
        var ip = parts[0];
        var prefix = parseInt(parts[1], 10);
        if (isNaN(prefix) || prefix < 0 || prefix > 32) return null;

        var octets = ip.split('.');
        if (octets.length !== 4) return null;

        var num = 0;
        for (var i = 0; i < 4; i++) {
            var o = parseInt(octets[i], 10);
            if (isNaN(o) || o < 0 || o > 255) return null;
            num = (num >>> 0) + (o << (24 - i * 8));
        }
        return { ip: num >>> 0, prefix: prefix };
    }

    function toIPv4(num) {
        num = num >>> 0;
        return [
            (num >>> 24) & 0xFF,
            (num >>> 16) & 0xFF,
            (num >>> 8) & 0xFF,
            num & 0xFF
        ].join('.');
    }

    function toBinary(num) {
        num = num >>> 0;
        var s = '';
        for (var i = 31; i >= 0; i--) {
            s += (num >>> i) & 1;
            if (i > 0 && i % 8 === 0) s += '.';
        }
        return s;
    }

    function ipClass(num) {
        var first = (num >>> 24) & 0xFF;
        if (first < 128) return 'A';
        if (first < 192) return 'B';
        if (first < 224) return 'C';
        if (first < 240) return 'D (Multicast)';
        return 'E (Reserved)';
    }

    function calcIPv4() {
        var input = document.getElementById('ipv4-input').value;
        var parsed = parseIPv4(input);
        if (!parsed) {
            alert('Invalid IPv4 address. Use format: 192.168.1.100/24');
            return;
        }

        var mask = parsed.prefix === 0 ? 0 : (0xFFFFFFFF << (32 - parsed.prefix)) >>> 0;
        var wildcard = (~mask) >>> 0;
        var network = (parsed.ip & mask) >>> 0;
        var broadcast = (network | wildcard) >>> 0;
        var first, last, hosts;

        if (parsed.prefix >= 31) {
            first = network;
            last = broadcast;
            hosts = parsed.prefix === 32 ? 1 : 2;
        } else {
            first = (network + 1) >>> 0;
            last = (broadcast - 1) >>> 0;
            hosts = Math.pow(2, 32 - parsed.prefix) - 2;
        }

        document.getElementById('v4-network').textContent = toIPv4(network);
        document.getElementById('v4-broadcast').textContent = toIPv4(broadcast);
        document.getElementById('v4-netmask').textContent = toIPv4(mask);
        document.getElementById('v4-wildcard').textContent = toIPv4(wildcard);
        document.getElementById('v4-cidr').textContent = toIPv4(network) + '/' + parsed.prefix;
        document.getElementById('v4-class').textContent = ipClass(parsed.ip);
        document.getElementById('v4-first').textContent = toIPv4(first);
        document.getElementById('v4-last').textContent = toIPv4(last);
        document.getElementById('v4-hosts').textContent = hosts.toLocaleString();
        document.getElementById('v4-binary').textContent = toBinary(mask);
        document.getElementById('ipv4-result').style.display = 'block';
    }

    document.getElementById('ipv4-calc').addEventListener('click', calcIPv4);
    document.getElementById('ipv4-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') calcIPv4();
    });

    function expandIPv6(addr) {
        if (addr.indexOf('::') !== -1) {
            var halves = addr.split('::');
            var left = halves[0] ? halves[0].split(':') : [];
            var right = halves[1] ? halves[1].split(':') : [];
            var missing = 8 - left.length - right.length;
            var mid = [];
            for (var m = 0; m < missing; m++) mid.push('0000');
            var groups = left.concat(mid).concat(right);
        } else {
            var groups = addr.split(':');
        }
        if (groups.length !== 8) return null;

        var expanded = [];
        for (var i = 0; i < 8; i++) {
            var g = groups[i];
            while (g.length < 4) g = '0' + g;
            if (!/^[0-9a-fA-F]{4}$/.test(g)) return null;
            expanded.push(g.toLowerCase());
        }
        return expanded;
    }

    function groupsToColonHex(groups) {
        return groups.join(':');
    }

    function calcIPv6() {
        var input = document.getElementById('ipv6-input').value.trim();
        var parts = input.split('/');
        var prefix = parseInt(parts[1], 10);
        if (isNaN(prefix) || prefix < 0 || prefix > 128) {
            alert('Invalid IPv6 address. Use format: 2001:db8::1/48');
            return;
        }

        var groups = expandIPv6(parts[0]);
        if (!groups) {
            alert('Invalid IPv6 address. Use format: 2001:db8::1/48');
            return;
        }

        var bits = [];
        for (var i = 0; i < 8; i++) {
            var val = parseInt(groups[i], 16);
            for (var b = 15; b >= 0; b--) {
                bits.push((val >> b) & 1);
            }
        }

        var networkBits = bits.slice();
        var firstBits = bits.slice();
        var lastBits = bits.slice();

        for (var i = 0; i < 128; i++) {
            if (i < prefix) {
                networkBits[i] = bits[i];
                firstBits[i] = bits[i];
                lastBits[i] = bits[i];
            } else {
                networkBits[i] = 0;
                firstBits[i] = 0;
                lastBits[i] = 1;
            }
        }

        function bitsToGroups(b) {
            var g = [];
            for (var i = 0; i < 8; i++) {
                var val = 0;
                for (var j = 0; j < 16; j++) {
                    val = (val << 1) | b[i * 16 + j];
                }
                g.push(val.toString(16).padStart(4, '0'));
            }
            return g;
        }

        var networkGroups = bitsToGroups(networkBits);
        var firstGroups = bitsToGroups(firstBits);
        var lastGroups = bitsToGroups(lastBits);

        var hostBits = 128 - prefix;
        var totalStr;
        if (hostBits === 0) {
            totalStr = '1';
        } else if (hostBits <= 53) {
            totalStr = Math.pow(2, hostBits).toLocaleString();
        } else {
            totalStr = '2^' + hostBits + ' (' + BigInt(2n ** BigInt(hostBits)).toLocaleString() + ')';
        }

        document.getElementById('v6-full').textContent = groupsToColonHex(groups);
        document.getElementById('v6-prefix').textContent = '/' + prefix;
        document.getElementById('v6-network').textContent = groupsToColonHex(networkGroups) + '/' + prefix;
        document.getElementById('v6-first').textContent = groupsToColonHex(firstGroups);
        document.getElementById('v6-last').textContent = groupsToColonHex(lastGroups);
        document.getElementById('v6-total').textContent = totalStr;
        document.getElementById('ipv6-result').style.display = 'block';
    }

    document.getElementById('ipv6-calc').addEventListener('click', calcIPv6);
    document.getElementById('ipv6-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') calcIPv6();
    });
})();
</script>
