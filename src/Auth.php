<?php

namespace PM2Manager;

use PDO;

class Auth {
    private $db;
    private $jwtSecret;

    public function __construct() {
        $this->db = Database::getInstance()->getPdo();
        $this->jwtSecret = getenv('JWT_SECRET') ?: 'my-pm2-default-secret-PLEASE-CHANGE-THIS';
    }

    public function login($username, $password) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            return null;
        }

        $token = $this->generateToken(['id' => $user['id'], 'username' => $user['username']]);
        return [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username']
            ]
        ];
    }

    public function verifyToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
        
        $signature = base64_decode(strtr($signatureEncoded, '-_', '+/'));
        $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $this->jwtSecret, true);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($payloadEncoded, '-_', '+/')), true);

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private function generateToken($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload['exp'] = time() + (7 * 24 * 60 * 60);
        $payload = json_encode($payload);

        $headerEncoded = $this->base64UrlEncode($header);
        $payloadEncoded = $this->base64UrlEncode($payload);
        
        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $this->jwtSecret, true);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function getAuthUser() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return null;
        }

        return $this->verifyToken($matches[1]);
    }
}
