<?php

namespace Coyote\Certificate;

class CertificateValidator
{
    private const MAX_UPLOAD_SIZE = 1048576;

    public static function validate(string $content, string $expectedType): array
    {
        $errors = [];

        if (trim($content) === '') {
            $errors[] = 'Certificate content is empty';
            return $errors;
        }

        if (!CertificateInfo::isValidPem($content)) {
            $errors[] = 'Content is not valid PEM data';
            return $errors;
        }

        if ($expectedType === CertificateStore::DIR_PRIVATE) {
            if (!CertificateInfo::isPemPrivateKey($content)) {
                $errors[] = 'Expected a PEM private key';
            }

            return $errors;
        }

        if (!in_array($expectedType, [
            CertificateStore::DIR_CA,
            CertificateStore::DIR_SERVER,
            CertificateStore::DIR_CLIENT,
        ], true)) {
            $errors[] = 'Unknown expected certificate type';
            return $errors;
        }

        if (!CertificateInfo::isPemCertificate($content)) {
            $errors[] = 'Expected a PEM certificate';
            return $errors;
        }

        $certificateInfo = CertificateInfo::parse($content);
        if ($certificateInfo === null) {
            $errors[] = 'Unable to parse certificate';
            return $errors;
        }

        if ($expectedType === CertificateStore::DIR_CA && !($certificateInfo['is_ca'] ?? false)) {
            $errors[] = 'Certificate is not a CA certificate';
        }

        return $errors;
    }

    public static function validateUpload(array $fileInfo): array
    {
        $errors = [];

        if (!isset($fileInfo['error'])) {
            $errors[] = 'Upload metadata is missing error code';
            return $errors;
        }

        $uploadError = (int)$fileInfo['error'];
        if ($uploadError !== UPLOAD_ERR_OK) {
            $errors[] = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds allowed size',
                UPLOAD_ERR_PARTIAL => 'Uploaded file was only partially received',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload directory is missing',
                UPLOAD_ERR_CANT_WRITE => 'Unable to write uploaded file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload was stopped by a PHP extension',
                default => 'Upload failed with an unknown error',
            };

            return $errors;
        }

        $size = (int)($fileInfo['size'] ?? 0);
        if ($size <= 0) {
            $errors[] = 'Uploaded file is empty';
            return $errors;
        }

        if ($size > self::MAX_UPLOAD_SIZE) {
            $errors[] = 'Uploaded file exceeds maximum size of 1MB';
        }

        $tmpName = $fileInfo['tmp_name'] ?? '';
        if (!is_string($tmpName) || $tmpName === '') {
            $errors[] = 'Uploaded file temporary path is missing';
            return $errors;
        }

        if (!file_exists($tmpName)) {
            $errors[] = 'Uploaded file is not accessible';
        }

        $name = $fileInfo['name'] ?? '';
        if (!is_string($name) || trim($name) === '') {
            $errors[] = 'Uploaded file name is missing';
        }

        return $errors;
    }

    public static function validateKeyPair(string $certPem, string $keyPem): array
    {
        $errors = [];

        if (!CertificateInfo::isPemCertificate($certPem)) {
            $errors[] = 'Certificate must be a PEM certificate';
        }

        if (!CertificateInfo::isPemPrivateKey($keyPem)) {
            $errors[] = 'Private key must be a PEM private key';
        }

        if (!empty($errors)) {
            return $errors;
        }

        if (!CertificateInfo::certMatchesKey($certPem, $keyPem)) {
            $errors[] = 'Certificate does not match private key';
        }

        return $errors;
    }

    public static function importPkcs12(string $pkcs12Content, string $password = ''): ?array
    {
        $bundle = [];
        if (!@openssl_pkcs12_read($pkcs12Content, $bundle, $password)) {
            return null;
        }

        $certificate = $bundle['cert'] ?? null;
        $privateKey = $bundle['pkey'] ?? null;

        if (!is_string($certificate) || $certificate === '' || !is_string($privateKey) || $privateKey === '') {
            return null;
        }

        $chain = [];
        $extraCertificates = $bundle['extracerts'] ?? [];

        if (is_array($extraCertificates)) {
            foreach ($extraCertificates as $extraCertificate) {
                if (is_string($extraCertificate) && $extraCertificate !== '') {
                    $chain[] = $extraCertificate;
                }
            }
        }

        return [
            'cert' => $certificate,
            'key' => $privateKey,
            'chain' => $chain,
        ];
    }
}
