<?php $pageTitle = 'DHCP Reservations'; $page = 'dhcp'; ?>

<div class="dashboard-grid">
    <div class="card full-width">
        <h3>DHCP Reservations</h3>
        <p>Configure static IP address assignments based on MAC addresses. These reservations ensure specific devices always receive the same IP address from the DHCP server.</p>

        <form method="post" action="/dhcp/reservations">
            <table>
                <thead>
                    <tr>
                        <th>MAC Address</th>
                        <th>IP Address</th>
                        <th>Hostname (Optional)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reservations ?? [] as $idx => $reservation): ?>
                    <tr>
                        <td>
                            <input type="text" name="mac[]" value="<?= htmlspecialchars($reservation['mac'] ?? '') ?>" placeholder="00:11:22:33:44:55" pattern="^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$" required style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="ip[]" value="<?= htmlspecialchars($reservation['ip'] ?? '') ?>" placeholder="192.168.1.50" required style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="hostname[]" value="<?= htmlspecialchars($reservation['hostname'] ?? '') ?>" placeholder="device-name" style="width: 100%;">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Empty row for adding new reservation -->
                    <tr>
                        <td>
                            <input type="text" name="mac[]" value="" placeholder="00:11:22:33:44:55" pattern="^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$" style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="ip[]" value="" placeholder="192.168.1.50" style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="hostname[]" value="" placeholder="device-name" style="width: 100%;">
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <input type="text" name="mac[]" value="" placeholder="00:11:22:33:44:55" pattern="^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$" style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="ip[]" value="" placeholder="192.168.1.50" style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="hostname[]" value="" placeholder="device-name" style="width: 100%;">
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <input type="text" name="mac[]" value="" placeholder="00:11:22:33:44:55" pattern="^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$" style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="ip[]" value="" placeholder="192.168.1.50" style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="hostname[]" value="" placeholder="device-name" style="width: 100%;">
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top: 1rem;">
                <button type="submit" class="btn btn-primary">Save Reservations</button>
                <a href="/dhcp" class="btn">Back to DHCP Settings</a>
                <small class="form-note">Changes are saved but not applied until you click "Apply Configuration"</small>
            </div>
        </form>

        <div style="margin-top: 1.5rem;">
            <h4>Notes:</h4>
            <ul>
                <li>MAC addresses must be in format: <code>00:11:22:33:44:55</code> or <code>00-11-22-33-44-55</code></li>
                <li>IP addresses must be within your DHCP range or network subnet</li>
                <li>Hostname is optional but recommended for identification</li>
                <li>Empty rows will be ignored when saving</li>
            </ul>
        </div>
    </div>
</div>
