<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\Certificate\AcmeService;
use Coyote\Certificate\CertificateInfo;
use Coyote\Certificate\CertificateStore;
use Coyote\Certificate\CertificateValidator;
use Coyote\System\PrivilegedExecutor;
use Coyote\WebAdmin\Service\ConfigService;

class CertificateController extends BaseController
{
    private CertificateStore $store;

    /** @var ConfigService */
    private ConfigService $configService;

    public function __construct()
    {
        parent::__construct();
        $this->store = new CertificateStore();
        $this->configService = new ConfigService();
    }

    public function index(array $params = []): void
    {
        if (!$this->store->initialize()) {
            $this->flash('error', 'Unable to initialize certificate store');
            $this->render('pages/certificates', [
                'certificates' => [],
                'caCount' => 0,
                'serverCount' => 0,
                'clientCount' => 0,
                'privateCount' => 0,
                'totalCount' => 0,
            ]);
            return;
        }

        $entries = $this->store->list();
        $certificates = [];
        $counts = [
            CertificateStore::DIR_CA => 0,
            CertificateStore::DIR_SERVER => 0,
            CertificateStore::DIR_CLIENT => 0,
            CertificateStore::DIR_PRIVATE => 0,
        ];

        foreach ($entries as $entry) {
            $type = (string)($entry['type'] ?? '');
            $id = (string)($entry['id'] ?? '');

            if (isset($counts[$type])) {
                $counts[$type]++;
            }

            $info = null;
            if ($type !== CertificateStore::DIR_PRIVATE && $id !== '') {
                $content = $this->store->getContent($id);
                if (is_string($content)) {
                    $info = CertificateInfo::parse($content);
                }
            }

            $entry['info'] = $info;
            $certificates[] = $entry;
        }

        usort($certificates, static function (array $left, array $right): int {
            return ((int)($right['created_at'] ?? 0)) <=> ((int)($left['created_at'] ?? 0));
        });

        $this->render('pages/certificates', array_merge([
            'certificates' => $certificates,
            'caCount' => $counts[CertificateStore::DIR_CA],
            'serverCount' => $counts[CertificateStore::DIR_SERVER],
            'clientCount' => $counts[CertificateStore::DIR_CLIENT],
            'privateCount' => $counts[CertificateStore::DIR_PRIVATE],
            'totalCount' => count($certificates),
        ], $this->buildSslCertificateData()));
    }

    public function upload(array $params = []): void
    {
        $this->render('pages/certificates/upload', [
            'types' => CertificateStore::VALID_TYPES,
        ]);
    }

    public function saveUpload(array $params = []): void
    {
        if (!$this->store->initialize()) {
            $this->flash('error', 'Unable to initialize certificate store');
            $this->redirect('/certificates');
            return;
        }

        $type = trim((string)$this->post('type', ''));
        $name = trim((string)$this->post('name', ''));
        $pemContentInput = trim((string)$this->post('pem_content', ''));

        if (!in_array($type, CertificateStore::VALID_TYPES, true)) {
            $this->flash('error', 'Invalid certificate type');
            $this->redirect('/certificates/upload');
            return;
        }

        if ($name === '') {
            $this->flash('error', 'Certificate name is required');
            $this->redirect('/certificates/upload');
            return;
        }

        $fileInfo = $_FILES['certificate'] ?? null;
        $hasUploadedFile = is_array($fileInfo)
            && ((int)($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

        $content = '';
        $metadata = [];

        if ($hasUploadedFile) {
            $uploadErrors = CertificateValidator::validateUpload($fileInfo);
            if (!empty($uploadErrors)) {
                $this->flash('error', implode('. ', $uploadErrors));
                $this->redirect('/certificates/upload');
                return;
            }

            $tmpName = (string)($fileInfo['tmp_name'] ?? '');
            $fileContent = file_get_contents($tmpName);
            if (!is_string($fileContent) || trim($fileContent) === '') {
                $this->flash('error', 'Unable to read uploaded file');
                $this->redirect('/certificates/upload');
                return;
            }

            $content = $fileContent;
            $metadata['source'] = 'upload';
            $metadata['filename'] = (string)($fileInfo['name'] ?? '');
        } else {
            if ($pemContentInput === '') {
                $this->flash('error', 'Upload a file or paste PEM content');
                $this->redirect('/certificates/upload');
                return;
            }

            $content = $pemContentInput;
            $metadata['source'] = 'paste';
        }

        $validationErrors = CertificateValidator::validate($content, $type);
        if (!empty($validationErrors)) {
            $this->flash('error', implode('. ', $validationErrors));
            $this->redirect('/certificates/upload');
            return;
        }

        if ($type !== CertificateStore::DIR_PRIVATE) {
            $parsedInfo = CertificateInfo::parse($content);
            if (is_array($parsedInfo)) {
                $metadata = array_merge($metadata, $parsedInfo);
            }
        }

        $storedId = $this->store->store($type, $name, $content, $metadata);
        if ($storedId === false) {
            $this->flash('error', 'Failed to store certificate');
            $this->redirect('/certificates/upload');
            return;
        }

        $this->flash('success', 'Certificate stored successfully');
        $this->redirect('/certificates');
    }

    public function acme(array $params = []): void
    {
        $acme = $this->getAcmeService();
        $accountInfo = $acme->getAccountInfo();
        $managedEntries = $acme->listManagedCertificates();
        $managedCertificates = [];

        foreach ($managedEntries as $entry) {
            if (($entry['type'] ?? '') === CertificateStore::DIR_SERVER) {
                $managedCertificates[] = $entry;
            }
        }

        usort($managedCertificates, static function (array $left, array $right): int {
            $leftDomain = strtolower((string)(($left['metadata'] ?? [])['domain'] ?? $left['name'] ?? ''));
            $rightDomain = strtolower((string)(($right['metadata'] ?? [])['domain'] ?? $right['name'] ?? ''));
            return strcmp($leftDomain, $rightDomain);
        });

        $this->render('pages/certificates/acme', [
            'registered' => $acme->isRegistered(),
            'accountInfo' => $accountInfo,
            'managedCertificates' => $managedCertificates,
        ]);
    }

    public function acmeRegister(array $params = []): void
    {
        $email = trim((string)$this->post('email', ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->flash('error', 'A valid email address is required');
            $this->redirect('/certificates/acme');
            return;
        }

        $acme = $this->getAcmeService();
        if ($acme->register($email)) {
            $this->flash('success', 'ACME account registered successfully');
        } else {
            $this->flash('error', 'Failed to register ACME account');
        }

        $this->redirect('/certificates/acme');
    }

    public function acmeRequest(array $params = []): void
    {
        $domain = strtolower(trim((string)$this->post('domain', '')));
        if (!$this->isValidDomain($domain)) {
            $this->flash('error', 'A valid domain name is required');
            $this->redirect('/certificates/acme');
            return;
        }

        $acme = $this->getAcmeService();
        $result = $acme->requestCertificate($domain);

        if (($result['success'] ?? false) === true) {
            $this->flash('success', (string)($result['message'] ?? 'Certificate requested successfully'));
        } else {
            $this->flash('error', (string)($result['message'] ?? 'Certificate request failed'));
        }

        $this->redirect('/certificates/acme');
    }

    public function acmeRenew(array $params = []): void
    {
        $domain = strtolower(trim((string)$this->post('domain', '')));
        if (!$this->isValidDomain($domain)) {
            $this->flash('error', 'A valid domain name is required');
            $this->redirect('/certificates/acme');
            return;
        }

        $acme = $this->getAcmeService();
        $result = $acme->renewCertificate($domain);

        if (($result['success'] ?? false) === true) {
            $this->flash('success', (string)($result['message'] ?? 'Certificate renewed successfully'));
        } else {
            $this->flash('error', (string)($result['message'] ?? 'Certificate renewal failed'));
        }

        $this->redirect('/certificates/acme');
    }

    public function acmeRenewAll(array $params = []): void
    {
        $acme = $this->getAcmeService();
        $result = $acme->renewAll();

        $renewedCount = count($result['renewed'] ?? []);
        $skippedCount = count($result['skipped'] ?? []);
        $failedCount = count($result['failed'] ?? []);

        if ($failedCount === 0 && $renewedCount === 0) {
            $this->flash('info', 'No ACME certificates needed renewal');
            $this->redirect('/certificates/acme');
            return;
        }

        if ($failedCount === 0) {
            $this->flash('success', "Renewed {$renewedCount} certificate(s); skipped {$skippedCount}");
            $this->redirect('/certificates/acme');
            return;
        }

        $this->flash('warning', "Renewed {$renewedCount} certificate(s), skipped {$skippedCount}, failed {$failedCount}");
        $this->redirect('/certificates/acme');
    }

    public function view(array $params = []): void
    {
        if (!$this->store->initialize()) {
            $this->flash('error', 'Unable to initialize certificate store');
            $this->redirect('/certificates');
            return;
        }

        $id = (string)($params['id'] ?? '');
        if ($id === '') {
            $this->flash('error', 'Certificate ID is required');
            $this->redirect('/certificates');
            return;
        }

        $entry = $this->store->get($id);
        if ($entry === null) {
            $this->flash('error', 'Certificate not found');
            $this->redirect('/certificates');
            return;
        }

        $content = $this->store->getContent($id) ?? '';
        $info = null;

        if (($entry['type'] ?? '') !== CertificateStore::DIR_PRIVATE && $content !== '') {
            $info = CertificateInfo::parse($content);
        }

        $this->render('pages/certificates/view', [
            'entry' => $entry,
            'info' => $info,
            'content' => $content,
        ]);
    }

    public function delete(array $params = []): void
    {
        if (!$this->store->initialize()) {
            $this->flash('error', 'Unable to initialize certificate store');
            $this->redirect('/certificates');
            return;
        }

        $id = (string)($params['id'] ?? '');
        if ($id === '') {
            $this->flash('error', 'Certificate ID is required');
            $this->redirect('/certificates');
            return;
        }

        if ($this->store->delete($id)) {
            $this->flash('success', 'Certificate deleted successfully');
        } else {
            $this->flash('error', 'Failed to delete certificate');
        }

        $this->redirect('/certificates');
    }

    /**
     * Apply a server certificate as the web admin SSL certificate.
     */
    public function saveSslCertificate(array $params = []): void
    {
        $certificateId = trim((string)$this->post('ssl_cert_id', ''));
        if ($certificateId === '') {
            $this->flash('error', 'Please select a server certificate');
            $this->redirect('/certificates#ssl-certificate');
            return;
        }

        if (!$this->store->initialize()) {
            $this->flash('error', 'Unable to initialize certificate store');
            $this->redirect('/certificates#ssl-certificate');
            return;
        }

        $entry = $this->store->get($certificateId);
        if ($entry === null || ($entry['type'] ?? '') !== CertificateStore::DIR_SERVER) {
            $this->flash('error', 'Selected certificate is not available');
            $this->redirect('/certificates#ssl-certificate');
            return;
        }

        $certificatePath = (string)($this->store->getPath($certificateId) ?? '');
        if ($certificatePath === '' || !file_exists($certificatePath)) {
            $this->flash('error', 'Selected certificate path could not be resolved');
            $this->redirect('/certificates#ssl-certificate');
            return;
        }

        $certContent = file_get_contents($certificatePath);
        if (!is_string($certContent) || trim($certContent) === '') {
            $this->flash('error', 'Unable to read selected certificate');
            $this->redirect('/certificates#ssl-certificate');
            return;
        }

        $combinedPem = $this->buildCombinedPem($certContent);
        if ($combinedPem === '') {
            $this->flash('error', 'No matching private key was found for the selected certificate');
            $this->redirect('/certificates#ssl-certificate');
            return;
        }

        if (!$this->writeWebAdminSslPem($combinedPem)) {
            $this->flash('error', 'Failed to write SSL certificate file');
            $this->redirect('/certificates#ssl-certificate');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $config->set('services.webadmin.ssl_cert_id', $certificateId);
        $config->set('services.webadmin.ssl_cert_path', '/mnt/config/ssl/server.pem');
        if (!$this->configService->saveWorkingConfig($config)) {
            $this->flash('warning', 'SSL certificate applied, but selection could not be saved to configuration');
            $this->redirect('/certificates#ssl-certificate');
            return;
        }

        if (!$this->reloadLighttpd()) {
            $this->flash('error', 'Certificate updated, but failed to reload lighttpd');
            $this->redirect('/certificates#ssl-certificate');
            return;
        }

        $this->flash('success', 'Web admin SSL certificate updated');
        $this->redirect('/certificates#ssl-certificate');
    }

    /**
     * Build a combined PEM (certificate + private key) for lighttpd.
     */
    private function buildCombinedPem(string $certificateContent): string
    {
        $certificateContent = trim($certificateContent);
        if ($certificateContent === '') {
            return '';
        }

        if (CertificateInfo::isPemPrivateKey($certificateContent)) {
            return $certificateContent . "\n";
        }

        foreach ($this->store->listByType(CertificateStore::DIR_PRIVATE) as $entry) {
            $keyId = (string)($entry['id'] ?? '');
            if ($keyId === '') {
                continue;
            }

            $keyContent = $this->store->getContent($keyId);
            if (!is_string($keyContent) || !CertificateInfo::isPemPrivateKey($keyContent)) {
                continue;
            }

            if (CertificateInfo::certMatchesKey($certificateContent, $keyContent)) {
                return $certificateContent . "\n" . trim($keyContent) . "\n";
            }
        }

        return '';
    }

    /**
     * Write the combined PEM file for lighttpd.
     */
    private function writeWebAdminSslPem(string $pemContent): bool
    {
        if (!$this->remountConfig(true)) {
            return false;
        }

        $written = false;

        try {
            if (!is_dir('/mnt/config/ssl') && !mkdir('/mnt/config/ssl', 0700, true)) {
                return false;
            }

            if (file_put_contents('/mnt/config/ssl/server.pem', $pemContent) === false) {
                return false;
            }

            chmod('/mnt/config/ssl', 0700);
            chmod('/mnt/config/ssl/server.pem', 0600);
            $written = true;
        } finally {
            $remountedReadOnly = $this->remountConfig(false);
        }

        return $written && $remountedReadOnly;
    }

    /**
     * Reload lighttpd to pick up the new certificate.
     */
    private function reloadLighttpd(): bool
    {
        $command = (posix_getuid() === 0) ? 'rc-service lighttpd reload' : 'doas rc-service lighttpd reload';
        exec($command . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Remount the config partition.
     *
     * @param bool $writable True for read-write, false for read-only
     * @return bool True if successful
     */
    private function remountConfig(bool $writable): bool
    {
        $executor = new PrivilegedExecutor();

        if ($writable) {
            $result = $executor->mountConfigRw();
        } else {
            $result = $executor->mountConfigRo();
        }

        return $result['success'];
    }

    /**
     * Build template data for the SSL certificate assignment card.
     *
     * @return array Template variables for SSL certificate selection
     */
    private function buildSslCertificateData(): array
    {
        $storeReady = $this->store->initialize();
        $serverCerts = [];

        if ($storeReady) {
            foreach ($this->store->listByType(CertificateStore::DIR_SERVER) as $entry) {
                $id = (string)($entry['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $content = $this->store->getContent($id);
                $entry['info'] = is_string($content) ? CertificateInfo::parse($content) : null;
                $serverCerts[] = $entry;
            }
        }

        usort($serverCerts, static function (array $left, array $right): int {
            return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        $config = $this->configService->getWorkingConfig()->toArray();
        $currentSslCertId = (string)($config['services']['webadmin']['ssl_cert_id'] ?? '');
        $currentSslCertPath = (string)($config['services']['webadmin']['ssl_cert_path'] ?? '');

        if ($currentSslCertPath === '') {
            $currentSslCertPath = $this->readLighttpdPemFilePath();
        }
        if ($currentSslCertPath === '' && file_exists('/mnt/config/ssl/server.pem')) {
            $currentSslCertPath = '/mnt/config/ssl/server.pem';
        }

        $currentSslCertInfo = null;
        if ($currentSslCertPath !== '' && file_exists($currentSslCertPath)) {
            $currentContent = file_get_contents($currentSslCertPath);
            if (is_string($currentContent)) {
                $currentSslCertInfo = CertificateInfo::parse($currentContent);
            }
        }

        if (!is_array($currentSslCertInfo) && $currentSslCertId !== '' && $storeReady) {
            $selectedContent = $this->store->getContent($currentSslCertId);
            if (is_string($selectedContent)) {
                $currentSslCertInfo = CertificateInfo::parse($selectedContent);
            }
        }

        return [
            'serverCerts' => $serverCerts,
            'currentSslCertId' => $currentSslCertId,
            'currentSslCertPath' => $currentSslCertPath,
            'currentSslCertInfo' => $currentSslCertInfo,
        ];
    }

    /**
     * Read the PEM file path from lighttpd configuration.
     */
    private function readLighttpdPemFilePath(): string
    {
        if (!is_readable('/etc/lighttpd/lighttpd.conf')) {
            return '';
        }

        $contents = file_get_contents('/etc/lighttpd/lighttpd.conf');
        if (!is_string($contents)) {
            return '';
        }

        if (preg_match('/^\s*ssl\.pemfile\s*=\s*"([^"]+)"/m', $contents, $matches) !== 1) {
            return '';
        }

        return trim((string)$matches[1]);
    }

    private function getAcmeService(): AcmeService
    {
        return new AcmeService($this->store);
    }

    private function isValidDomain(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }

        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain) === 1;
    }
}
