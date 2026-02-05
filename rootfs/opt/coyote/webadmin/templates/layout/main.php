<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Coyote Linux') ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div id="sidebar-overlay" class="sidebar-overlay"></div>
    <nav class="sidebar" id="sidebar">
        <div class="logo">
            <img src="/assets/img/logo.png" alt="Coyote Linux" class="logo-img">
            <span class="logo-text">Coyote Linux</span>
            <span class="logo-version"><?= trim(@file_get_contents('/etc/coyote/version') ?: '4.0') ?></span>
        </div>
        <ul class="nav-menu">
            <li><a href="/dashboard" class="<?= ($page ?? '') === 'dashboard' ? 'active' : '' ?>">Dashboard</a></li>
            <li><a href="/network" class="<?= ($page ?? '') === 'network' ? 'active' : '' ?>">Network</a></li>
            <li><a href="/firewall" class="<?= ($page ?? '') === 'firewall' ? 'active' : '' ?>">Firewall</a></li>
            <li><a href="/nat" class="<?= ($page ?? '') === 'nat' ? 'active' : '' ?>">NAT</a></li>
            <li><a href="/vpn" class="<?= ($page ?? '') === 'vpn' ? 'active' : '' ?>">VPN</a></li>
            <li><a href="/loadbalancer" class="<?= ($page ?? '') === 'loadbalancer' ? 'active' : '' ?>">Load Balancer</a></li>
            <li><a href="/services" class="<?= ($page ?? '') === 'services' ? 'active' : '' ?>">Services</a></li>
            <li><a href="/system" class="<?= ($page ?? '') === 'system' ? 'active' : '' ?>">System</a></li>
            <li><a href="/firmware" class="<?= ($page ?? '') === 'firmware' ? 'active' : '' ?>">Firmware</a></li>
        </ul>
        <div class="nav-footer">
            <a href="/logout">Logout</a>
        </div>
    </nav>

    <main class="content">
        <header class="content-header">
            <button id="sidebar-toggle" class="sidebar-toggle" aria-label="Toggle Navigation">
                <span class="icon">☰</span>
            </button>
            <h1><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
        </header>

        <?php foreach ($this->getFlashMessages() as $flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" data-auto-dismiss="5000">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endforeach; ?>

        <div class="content-body">
            <?php
            // Include the page template
            $pageTemplate = $this->templatesPath . '/' . $template . '.php';
            if (file_exists($pageTemplate)) {
                include $pageTemplate;
            }
            ?>
        </div>
    </main>

    <script src="/assets/js/app.js"></script>
</body>
</html>
