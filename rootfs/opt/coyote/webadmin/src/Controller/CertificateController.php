<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\Certificate\AcmeService;
use Coyote\Certificate\CertificateInfo;
use Coyote\Certificate\CertificateStore;
use Coyote\Certificate\CertificateValidator;

class CertificateController extends BaseController
{
    private CertificateStore $store;

    public function __construct()
    {
        parent::__construct();
        $this->store = new CertificateStore();
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

        $this->render('pages/certificates', [
            'certificates' => $certificates,
            'caCount' => $counts[CertificateStore::DIR_CA],
            'serverCount' => $counts[CertificateStore::DIR_SERVER],
            'clientCount' => $counts[CertificateStore::DIR_CLIENT],
            'privateCount' => $counts[CertificateStore::DIR_PRIVATE],
            'totalCount' => count($certificates),
        ]);
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
