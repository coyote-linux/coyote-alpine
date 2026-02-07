<?php

namespace Coyote\Certificate;

class CertificateInfo
{
    public static function parse(string $pemContent): ?array
    {
        if (!self::isPemCertificate($pemContent)) {
            return null;
        }

        $certificate = @openssl_x509_read($pemContent);
        if ($certificate === false) {
            return null;
        }

        $parsedCertificate = @openssl_x509_parse($certificate);
        if ($parsedCertificate === false || !is_array($parsedCertificate)) {
            return null;
        }

        $validFrom = (int)($parsedCertificate['validFrom_time_t'] ?? 0);
        $validTo = (int)($parsedCertificate['validTo_time_t'] ?? 0);
        $now = time();
        $daysUntilExpiry = (int)floor(($validTo - $now) / 86400);
        $fingerprint = @openssl_x509_fingerprint($certificate, 'sha256');

        $keyType = 'UNKNOWN';
        $keyBits = 0;

        $publicKey = @openssl_pkey_get_public($pemContent);
        if ($publicKey !== false) {
            $keyDetails = @openssl_pkey_get_details($publicKey);
            if (is_array($keyDetails)) {
                $keyBits = (int)($keyDetails['bits'] ?? 0);
                $keyType = match ($keyDetails['type'] ?? null) {
                    OPENSSL_KEYTYPE_RSA => 'RSA',
                    OPENSSL_KEYTYPE_DSA => 'DSA',
                    OPENSSL_KEYTYPE_DH => 'DH',
                    OPENSSL_KEYTYPE_EC => 'EC',
                    default => 'UNKNOWN',
                };
            }
        }

        $serialHex = (string)($parsedCertificate['serialNumberHex'] ?? '');
        if ($serialHex === '') {
            $serialHex = strtoupper((string)($parsedCertificate['serialNumber'] ?? ''));
        }

        $isCa = false;
        if (isset($parsedCertificate['extensions']) && is_array($parsedCertificate['extensions'])) {
            $basicConstraints = (string)($parsedCertificate['extensions']['basicConstraints'] ?? '');
            $isCa = stripos($basicConstraints, 'CA:TRUE') !== false;
        }

        return [
            'subject' => self::formatDistinguishedName((array)($parsedCertificate['subject'] ?? [])),
            'issuer' => self::formatDistinguishedName((array)($parsedCertificate['issuer'] ?? [])),
            'serial' => strtoupper($serialHex),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'valid_from_human' => date('Y-m-d H:i:s', $validFrom),
            'valid_to_human' => date('Y-m-d H:i:s', $validTo),
            'is_expired' => $validTo < $now,
            'days_until_expiry' => $daysUntilExpiry,
            'san' => self::extractSan($parsedCertificate),
            'fingerprint_sha256' => is_string($fingerprint) ? strtolower($fingerprint) : '',
            'key_type' => $keyType,
            'key_bits' => $keyBits,
            'is_ca' => $isCa,
        ];
    }

    public static function isValidPem(string $content): bool
    {
        return preg_match('/-----BEGIN [A-Z0-9 ]+-----/', $content) === 1;
    }

    public static function isPemCertificate(string $content): bool
    {
        return strpos($content, '-----BEGIN CERTIFICATE-----') !== false;
    }

    public static function isPemPrivateKey(string $content): bool
    {
        return strpos($content, '-----BEGIN PRIVATE KEY-----') !== false
            || strpos($content, '-----BEGIN RSA PRIVATE KEY-----') !== false
            || strpos($content, '-----BEGIN EC PRIVATE KEY-----') !== false;
    }

    public static function getKeyFingerprint(string $pemKey): ?string
    {
        $keyResource = @openssl_pkey_get_private($pemKey);
        if ($keyResource === false) {
            $keyResource = @openssl_pkey_get_public($pemKey);
        }

        if ($keyResource === false) {
            return null;
        }

        $keyDetails = @openssl_pkey_get_details($keyResource);
        if (!is_array($keyDetails) || !isset($keyDetails['key']) || !is_string($keyDetails['key'])) {
            return null;
        }

        return hash('sha256', $keyDetails['key']);
    }

    public static function certMatchesKey(string $certPem, string $keyPem): bool
    {
        $certificate = @openssl_x509_read($certPem);
        if ($certificate === false) {
            return false;
        }

        $privateKey = @openssl_pkey_get_private($keyPem);
        if ($privateKey === false) {
            return false;
        }

        return @openssl_x509_check_private_key($certificate, $privateKey);
    }

    private static function formatDistinguishedName(array $dn): string
    {
        $orderedFields = ['CN', 'O', 'OU', 'L', 'ST', 'C', 'emailAddress'];
        $parts = [];

        foreach ($orderedFields as $field) {
            if (!array_key_exists($field, $dn)) {
                continue;
            }

            $value = $dn[$field];
            if (is_array($value)) {
                foreach ($value as $arrayValue) {
                    if (is_scalar($arrayValue)) {
                        $parts[] = $field . '=' . (string)$arrayValue;
                    }
                }
                continue;
            }

            if (is_scalar($value)) {
                $parts[] = $field . '=' . (string)$value;
            }
        }

        foreach ($dn as $field => $value) {
            if (in_array((string)$field, $orderedFields, true)) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $arrayValue) {
                    if (is_scalar($arrayValue)) {
                        $parts[] = (string)$field . '=' . (string)$arrayValue;
                    }
                }
                continue;
            }

            if (is_scalar($value)) {
                $parts[] = (string)$field . '=' . (string)$value;
            }
        }

        return implode(', ', $parts);
    }

    private static function extractSan(array $parsedCert): array
    {
        if (!isset($parsedCert['extensions']) || !is_array($parsedCert['extensions'])) {
            return [];
        }

        $subjectAltName = $parsedCert['extensions']['subjectAltName'] ?? '';
        if (!is_string($subjectAltName) || trim($subjectAltName) === '') {
            return [];
        }

        $entries = explode(',', $subjectAltName);
        $sanValues = [];

        foreach ($entries as $entry) {
            $trimmedEntry = trim($entry);
            if ($trimmedEntry === '') {
                continue;
            }

            $parts = explode(':', $trimmedEntry, 2);
            if (count($parts) === 2) {
                $sanValues[] = trim($parts[1]);
            } else {
                $sanValues[] = $trimmedEntry;
            }
        }

        return $sanValues;
    }
}
