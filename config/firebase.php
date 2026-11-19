<?php
// =====================================================================
// TC LIMITED - Firebase Real-Time Synchronization Config & Service
// =====================================================================

define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: 'tc-limited-grocery');
define('FIREBASE_DATABASE_URL', getenv('FIREBASE_DATABASE_URL') ?: 'https://tc-limited-grocery-default-rtdb.firebaseio.com');
define('FIREBASE_API_KEY', getenv('FIREBASE_API_KEY') ?: 'AIzaSyDemoKeyTCLimitedGrocery2026');
define('FIREBASE_MESSAGING_SENDER_ID', getenv('FIREBASE_MESSAGING_SENDER_ID') ?: '987654321012');
define('FIREBASE_APP_ID', getenv('FIREBASE_APP_ID') ?: '1:987654321012:web:abcdef1234567890');

class FirebaseService {
    /**
     * Broadcast an event to Firebase Realtime Database
     * Events: 'STOCK_UPDATE', 'EXPIRY_ALERT', 'LOW_STOCK', 'NEW_SALE', 'AUTO_PO', 'WASTAGE_LOG'
     */
    public static function broadcast(string $eventType, array $payload): array {
        $eventData = [
            'type' => $eventType,
            'payload' => $payload,
            'timestamp' => date('c'),
            'timestamp_ms' => round(microtime(true) * 1000)
        ];

        // 1. Persist to local event log for instant zero-config fallback
        $scratchDir = __DIR__ . '/../scratch';
        if (!is_dir($scratchDir) && !@mkdir($scratchDir, 0777, true)) {
            $scratchDir = sys_get_temp_dir();
        } elseif (!is_writable($scratchDir)) {
            $scratchDir = sys_get_temp_dir();
        }
        $localLogPath = rtrim($scratchDir, '/\\') . '/firebase_events.json';

        $events = [];
        if (file_exists($localLogPath)) {
            $raw = @file_get_contents($localLogPath);
            $events = json_decode($raw, true) ?: [];
        }
        // Keep last 100 events
        array_unshift($events, $eventData);
        if (count($events) > 100) {
            $events = array_slice($events, 0, 100);
        }
        @file_put_contents($localLogPath, json_encode($events, JSON_PRETTY_PRINT));

        // 2. Attempt real-time POST to Firebase REST endpoint if available
        $url = rtrim(FIREBASE_DATABASE_URL, '/') . '/events.json';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($eventData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2 second fast timeout
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'broadcast' => true,
            'event' => $eventData,
            'firebase_synced' => ($httpCode >= 200 && $httpCode < 300)
        ];
    }

    /**
     * Get client configuration array for frontend SDK
     */
    public static function getClientConfig(): array {
        return [
            'apiKey' => FIREBASE_API_KEY,
            'authDomain' => FIREBASE_PROJECT_ID . '.firebaseapp.com',
            'databaseURL' => FIREBASE_DATABASE_URL,
            'projectId' => FIREBASE_PROJECT_ID,
            'storageBucket' => FIREBASE_PROJECT_ID . '.appspot.com',
            'messagingSenderId' => FIREBASE_MESSAGING_SENDER_ID,
            'appId' => FIREBASE_APP_ID
        ];
    }
}
