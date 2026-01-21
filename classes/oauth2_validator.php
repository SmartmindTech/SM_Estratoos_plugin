<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * OAuth2/OIDC JWT validator for SmartLearning iframe embedding.
 *
 * This class validates JWT tokens issued by SmartLearning for chromeless
 * activity embedding in iframes. It fetches and caches JWKS from SmartLearning
 * and verifies RS256 signed JWTs.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin;

defined('MOODLE_INTERNAL') || die();

/**
 * OAuth2/OIDC JWT validator class.
 */
class oauth2_validator {

    /** @var int JWKS cache TTL in seconds (1 hour) */
    const JWKS_CACHE_TTL = 3600;

    /** @var string Expected issuer URL (SmartLearning backend) */
    private $issuer;

    /** @var string This Moodle's URL (for audience validation) */
    private $audience;

    /**
     * Constructor.
     *
     * @param string $issuer SmartLearning issuer URL (e.g., https://smartlearning.smartmind.net)
     * @param string|null $audience This Moodle's URL for audience validation (defaults to $CFG->wwwroot)
     */
    public function __construct(string $issuer, ?string $audience = null) {
        global $CFG;

        $this->issuer = rtrim($issuer, '/');
        $this->audience = $audience ?? rtrim($CFG->wwwroot, '/');
    }

    /**
     * Get Bearer token from Authorization header.
     *
     * @return string|null Bearer token or null if not present
     */
    public static function get_bearer_token(): ?string {
        $headers = [];

        // Get Authorization header (works with Apache mod_rewrite).
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
        }

        // Fallback to $_SERVER.
        if (empty($headers['Authorization'])) {
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }
        }

        // Also check query parameter as fallback for iframes (where headers can be tricky).
        if (empty($headers['Authorization']) && isset($_GET['token'])) {
            return $_GET['token'];
        }

        if (empty($headers['Authorization'])) {
            return null;
        }

        // Extract Bearer token.
        if (preg_match('/^Bearer\s+(.+)$/i', $headers['Authorization'], $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Validate a JWT token.
     *
     * @param string $token JWT string
     * @return object|null Decoded payload if valid, null otherwise
     */
    public function validate_jwt(string $token): ?object {
        try {
            // 1. Decode header (without verification) to get kid.
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                debugging('Invalid JWT format: expected 3 parts', DEBUG_DEVELOPER);
                return null;
            }

            $header = json_decode($this->base64url_decode($parts[0]));
            if (!$header || !isset($header->alg) || !isset($header->kid)) {
                debugging('Invalid JWT header: missing alg or kid', DEBUG_DEVELOPER);
                return null;
            }

            if ($header->alg !== 'RS256') {
                debugging("Unsupported JWT algorithm: {$header->alg}", DEBUG_DEVELOPER);
                return null;
            }

            // 2. Get JWKS from cache or fetch from SmartLearning.
            $jwks = $this->get_jwks();
            if (empty($jwks['keys'])) {
                debugging('No keys found in JWKS', DEBUG_DEVELOPER);
                return null;
            }

            // 3. Find matching key by kid.
            $publicKey = null;
            foreach ($jwks['keys'] as $key) {
                if (isset($key['kid']) && $key['kid'] === $header->kid) {
                    $publicKey = $this->jwk_to_pem($key);
                    break;
                }
            }

            if (!$publicKey) {
                debugging("No matching key found for kid: {$header->kid}", DEBUG_DEVELOPER);
                return null;
            }

            // 4. Verify signature.
            $signatureInput = $parts[0] . '.' . $parts[1];
            $signature = $this->base64url_decode($parts[2]);

            $verified = openssl_verify(
                $signatureInput,
                $signature,
                $publicKey,
                OPENSSL_ALGO_SHA256
            );

            if ($verified !== 1) {
                debugging('JWT signature verification failed', DEBUG_DEVELOPER);
                return null;
            }

            // 5. Decode and validate payload.
            $payload = json_decode($this->base64url_decode($parts[1]));
            if (!$payload) {
                debugging('Invalid JWT payload', DEBUG_DEVELOPER);
                return null;
            }

            // 6. Validate claims.
            $now = time();

            // Check expiration.
            if (!isset($payload->exp) || $payload->exp < $now) {
                debugging('JWT expired', DEBUG_DEVELOPER);
                return null;
            }

            // Check issued at (with 60 second leeway for clock skew).
            if (!isset($payload->iat) || $payload->iat > ($now + 60)) {
                debugging('JWT issued in the future', DEBUG_DEVELOPER);
                return null;
            }

            // Check issuer.
            if (!isset($payload->iss) || rtrim($payload->iss, '/') !== $this->issuer) {
                debugging("JWT issuer mismatch: expected {$this->issuer}, got {$payload->iss}", DEBUG_DEVELOPER);
                return null;
            }

            // Check audience.
            if (!isset($payload->aud) || rtrim($payload->aud, '/') !== $this->audience) {
                debugging("JWT audience mismatch: expected {$this->audience}, got {$payload->aud}", DEBUG_DEVELOPER);
                return null;
            }

            // Check required claims.
            $requiredClaims = ['sub', 'jti', 'moodle_user_id'];
            foreach ($requiredClaims as $claim) {
                if (!isset($payload->$claim)) {
                    debugging("JWT missing required claim: {$claim}", DEBUG_DEVELOPER);
                    return null;
                }
            }

            return $payload;

        } catch (\Exception $e) {
            debugging('JWT validation error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Get JWKS from cache or fetch from SmartLearning.
     *
     * @return array JWKS array with 'keys'
     */
    public function get_jwks(): array {
        global $DB;

        // Check cache first.
        $cached = $DB->get_record('local_sm_estratoos_jwks', [
            'issuer_url' => $this->issuer,
        ]);

        if ($cached && $cached->expires_at > time()) {
            return json_decode($cached->jwks_json, true) ?: ['keys' => []];
        }

        // Fetch from SmartLearning.
        $jwksUrl = $this->issuer . '/.well-known/jwks.json';

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $jwksUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($httpCode !== 200 || !$response) {
            debugging("Failed to fetch JWKS from {$jwksUrl}: HTTP {$httpCode}, Error: {$error}", DEBUG_DEVELOPER);
            // Return cached value if available (even if expired).
            if ($cached) {
                return json_decode($cached->jwks_json, true) ?: ['keys' => []];
            }
            return ['keys' => []];
        }

        $jwks = json_decode($response, true);
        if (!$jwks || !isset($jwks['keys'])) {
            debugging("Invalid JWKS response from {$jwksUrl}", DEBUG_DEVELOPER);
            return ['keys' => []];
        }

        // Cache the JWKS.
        $now = time();
        $record = (object)[
            'issuer_url' => $this->issuer,
            'jwks_json' => $response,
            'fetched_at' => $now,
            'expires_at' => $now + self::JWKS_CACHE_TTL,
        ];

        if ($cached) {
            $record->id = $cached->id;
            $DB->update_record('local_sm_estratoos_jwks', $record);
        } else {
            $DB->insert_record('local_sm_estratoos_jwks', $record);
        }

        return $jwks;
    }

    /**
     * Convert JWK to PEM format public key.
     *
     * @param array $jwk JWK array with kty, n, e
     * @return string|null PEM public key or null on error
     */
    private function jwk_to_pem(array $jwk): ?string {
        if (!isset($jwk['kty']) || $jwk['kty'] !== 'RSA') {
            return null;
        }

        if (!isset($jwk['n']) || !isset($jwk['e'])) {
            return null;
        }

        $n = $this->base64url_decode($jwk['n']);
        $e = $this->base64url_decode($jwk['e']);

        // Convert to ASN.1 DER format.
        $modulus = $this->encode_asn1_integer($n);
        $exponent = $this->encode_asn1_integer($e);

        $publicKeySeq = $this->encode_asn1_sequence($modulus . $exponent);
        $bitString = chr(0x03) . $this->encode_asn1_length(strlen($publicKeySeq) + 1) . chr(0x00) . $publicKeySeq;

        // RSA OID: 1.2.840.113549.1.1.1
        $algorithmOid = pack('H*', '06092a864886f70d010101');
        $algorithmNull = pack('H*', '0500');
        $algorithmSeq = $this->encode_asn1_sequence($algorithmOid . $algorithmNull);

        $publicKeyInfo = $this->encode_asn1_sequence($algorithmSeq . $bitString);

        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($publicKeyInfo), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    /**
     * Base64url decode.
     *
     * @param string $data Base64url encoded data
     * @return string Decoded data
     */
    private function base64url_decode(string $data): string {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Encode ASN.1 integer.
     *
     * @param string $data Binary integer data
     * @return string ASN.1 encoded integer
     */
    private function encode_asn1_integer(string $data): string {
        // Remove leading zeros.
        while (strlen($data) > 1 && ord($data[0]) === 0 && !(ord($data[1]) & 0x80)) {
            $data = substr($data, 1);
        }
        // Add leading zero if high bit is set.
        if (ord($data[0]) & 0x80) {
            $data = chr(0x00) . $data;
        }
        return chr(0x02) . $this->encode_asn1_length(strlen($data)) . $data;
    }

    /**
     * Encode ASN.1 sequence.
     *
     * @param string $data Sequence content
     * @return string ASN.1 encoded sequence
     */
    private function encode_asn1_sequence(string $data): string {
        return chr(0x30) . $this->encode_asn1_length(strlen($data)) . $data;
    }

    /**
     * Encode ASN.1 length.
     *
     * @param int $length Length value
     * @return string Encoded length
     */
    private function encode_asn1_length(int $length): string {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        $temp = $length;
        while ($temp > 0) {
            $bytes = chr($temp & 0xFF) . $bytes;
            $temp >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Validate token and get Moodle user.
     *
     * @param string $token JWT token
     * @return object|null Moodle user record or null if invalid
     */
    public function get_user_from_token(string $token): ?object {
        global $DB;

        $payload = $this->validate_jwt($token);
        if (!$payload) {
            return null;
        }

        // Get Moodle user by ID.
        $user = $DB->get_record('user', [
            'id' => $payload->moodle_user_id,
            'deleted' => 0,
            'suspended' => 0,
        ]);

        return $user ?: null;
    }
}
