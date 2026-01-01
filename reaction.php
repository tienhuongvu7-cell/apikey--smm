<?php
/**
 * API Buff SMM - Full Functionality
 * Version: 3.0 - Fixed Quantity Parsing & New Response Format
 */

class SMMBuffAPI {
    // GitHub configuration
    private $githubRepo = 'tienhuongvu7-cell/apikey--smm';
    private $githubFile = 'apikeyreact.txt';
    private $githubToken = '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';
    
    // SMM configuration
    private $smmApiUrl = 'https://smm-center.com/api/v2';
    private $serviceId = 28417;
    private $maxPerKey = 934;
    
    // Response data
    private $responseData = [
        'status_code' => '500',
        'url' => '',
        'quantity' => 0,
        'order_id' => 0,
        'remaining_total' => 0,
        'success' => false
    ];
    
    public function __construct() {
        // Set error handling
        error_reporting(0);
        set_time_limit(30);
        
        // Enable CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    
    /**
     * Parse URL and quantity from request - FIXED VERSION
     */
    private function parseRequest() {
        // Get raw query string
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        
        // Parse query string manually to handle special characters
        parse_str($queryString, $params);
        
        $url = '';
        $quantity = 0;
        
        // Check for URL parameter
        if (isset($params['url'])) {
            $url = trim($params['url']);
            
            // Remove any quantity parameter that might be in the URL itself
            if (preg_match('/(.*?)(\?|\&)quantity=(\d+)/i', $url, $matches)) {
                $url = $matches[1]; // Get URL without quantity parameter
                if (empty($quantity)) {
                    $quantity = intval($matches[3]);
                }
            }
            
            // Decode URL
            $url = urldecode($url);
            $url = trim($url);
        }
        
        // Check for separate quantity parameter (highest priority)
        if (isset($params['quantity'])) {
            $quantity = intval($params['quantity']);
        }
        
        // If quantity is 0, check if it's in the URL path
        if ($quantity <= 0 && isset($_GET['quantity'])) {
            $quantity = intval($_GET['quantity']);
        }
        
        // Validate URL
        if (!empty($url) && !preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "https://" . $url;
        }
        
        return [
            'url' => $url,
            'quantity' => $quantity,
            'raw_params' => $params
        ];
    }
    
    /**
     * Main API handler
     */
    public function handleRequest() {
        // Handle OPTIONS preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit(0);
        }
        
        // Parse request
        $parsed = $this->parseRequest();
        $url = $parsed['url'];
        $quantity = $parsed['quantity'];
        
        // Store in response
        $this->responseData['url'] = $url;
        $this->responseData['quantity'] = $quantity;
        
        // Validate inputs
        if (empty($url)) {
            $this->responseData['status_code'] = '400';
            $this->buildResponse("INVALID REQUEST - URL is required");
            $this->outputResponse();
            exit;
        }
        
        if ($quantity <= 0) {
            $this->responseData['status_code'] = '400';
            $this->buildResponse("INVALID REQUEST - Quantity must be greater than 0");
            $this->outputResponse();
            exit;
        }
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->responseData['status_code'] = '400';
            $this->buildResponse("INVALID REQUEST - Invalid URL format");
            $this->outputResponse();
            exit;
        }
        
        // Load keys from GitHub
        $keysData = $this->loadKeysFromGitHub();
        if (empty($keysData['keys'])) {
            $this->responseData['status_code'] = '500';
            $this->responseData['remaining_total'] = 0;
            $this->buildFinalResponse();
            $this->outputResponse();
            exit;
        }
        
        // Process buff request
        $result = $this->processBuff($url, $quantity, $keysData);
        
        // Update response data
        $this->responseData['success'] = $result['success'];
        $this->responseData['order_id'] = $result['order_id'];
        $this->responseData['remaining_total'] = $result['remaining_count'] * $this->maxPerKey;
        $this->responseData['status_code'] = $result['success'] ? '200' : '500';
        
        // Update GitHub if keys were used
        if ($result['success'] && !empty($result['remaining_keys'])) {
            $this->updateKeysOnGitHub($result['remaining_keys']);
        }
        
        // Build and output response
        $this->buildFinalResponse();
        $this->outputResponse();
    }
    
    /**
     * Load API keys from GitHub
     */
    private function loadKeysFromGitHub() {
        $apiUrl = "https://api.github.com/repos/{$this->githubRepo}/contents/{$this->githubFile}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: token {$this->githubToken}",
                "User-Agent: SMM-Buff-API",
                "Accept: application/vnd.github.v3+json"
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['keys' => [], 'sha' => null];
        }
        
        $data = json_decode($response, true);
        if (!isset($data['content'])) {
            return ['keys' => [], 'sha' => null];
        }
        
        $content = base64_decode($data['content']);
        $sha = $data['sha'];
        
        $keys = [];
        $lines = array_filter(explode("\n", trim($content)));
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Parse key and remaining count
            $remaining = $this->maxPerKey;
            $apiKey = $line;
            
            // Check for various delimiters
            if (preg_match('/^([^|]+)\|(\d+)$/', $line, $matches)) {
                $apiKey = trim($matches[1]);
                $remaining = intval($matches[2]);
            } elseif (preg_match('/^([^,]+),(\d+)$/', $line, $matches)) {
                $apiKey = trim($matches[1]);
                $remaining = intval($matches[2]);
            }
            
            if (!empty($apiKey)) {
                $keys[] = [
                    'key' => $apiKey,
                    'remaining' => $remaining,
                    'original' => $apiKey . '|' . $remaining
                ];
            }
        }
        
        return ['keys' => $keys, 'sha' => $sha];
    }
    
    /**
     * Process buff request
     */
    private function processBuff($url, $quantity, $keysData) {
        $keys = $keysData['keys'];
        $remainingKeys = [];
        $success = false;
        $orderId = 0;
        $usedKeyIndex = -1;
        
        foreach ($keys as $index => $keyData) {
            if ($success) {
                $remainingKeys[] = $keyData;
                continue;
            }
            
            $apiKey = $keyData['key'];
            $remaining = $keyData['remaining'];
            
            // Check if key has enough remaining
            if ($remaining < $quantity) {
                $remainingKeys[] = $keyData;
                continue;
            }
            
            // Try to create order
            $orderResult = $this->createSMMOrder($apiKey, $url, $quantity);
            
            if ($orderResult['success']) {
                $success = true;
                $orderId = $orderResult['order_id'];
                $usedKeyIndex = $index;
                $newRemaining = $remaining - $quantity;
                
                if ($newRemaining > 0) {
                    $keyData['remaining'] = $newRemaining;
                    $keyData['original'] = $apiKey . '|' . $newRemaining;
                    $remainingKeys[] = $keyData;
                }
                // If newRemaining == 0, don't add back (key is used up)
            } else {
                $remainingKeys[] = $keyData;
            }
            
            // Small delay between API calls
            usleep(300000);
        }
        
        // If no key was used successfully, keep all keys
        if (!$success) {
            $remainingKeys = $keys;
        }
        
        return [
            'success' => $success,
            'order_id' => $orderId,
            'remaining_keys' => $remainingKeys,
            'remaining_count' => count($remainingKeys)
        ];
    }
    
    /**
     * Create order on SMM panel
     */
    private function createSMMOrder($apiKey, $url, $quantity) {
        $postData = [
            'key' => $apiKey,
            'action' => 'add',
            'service' => $this->serviceId,
            'link' => $url,
            'quantity' => $quantity
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->smmApiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'SMM API connection failed'];
        }
        
        $result = json_decode($response, true);
        if (isset($result['order'])) {
            return ['success' => true, 'order_id' => $result['order']];
        }
        
        return ['success' => false, 'error' => isset($result['error']) ? $result['error'] : 'Unknown error'];
    }
    
    /**
     * Update keys on GitHub
     */
    private function updateKeysOnGitHub($remainingKeys) {
        if (empty($remainingKeys)) {
            $content = "";
        } else {
            $lines = [];
            foreach ($remainingKeys as $keyData) {
                $lines[] = $keyData['original'];
            }
            $content = implode("\n", $lines);
        }
        
        // Get current SHA
        $currentData = $this->loadKeysFromGitHub();
        $sha = $currentData['sha'];
        
        if (empty($sha)) {
            return false;
        }
        
        $apiUrl = "https://api.github.com/repos/{$this->githubRepo}/contents/{$this->githubFile}";
        
        $data = [
            'message' => 'Auto update: ' . date('Y-m-d H:i:s'),
            'content' => base64_encode($content),
            'sha' => $sha
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "Authorization: token {$this->githubToken}",
                "User-Agent: SMM-Buff-API",
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return true;
    }
    
    /**
     * Build error response
     */
    private function buildResponse($message) {
        $this->responseData['message'] = $message;
    }
    
    /**
     * Build final response in new format
     */
    private function buildFinalResponse() {
        $data = $this->responseData;
        
        $response = "{" .
            "status_code}=" . $data['status_code'] . " ," .
            "{url}= " . $data['url'] . "," .
            "{quantity}=" . $data['quantity'] . " ," .
            "{order_id}=" . $data['order_id'] . "\n";
        
        $response .= "url = " . $data['url'] . "\n";
        $response .= "số lg buff: " . $data['quantity'] . "\n";
        $response .= "còn lại: " . $data['remaining_total'];
        
        $this->responseData['full_response'] = $response;
    }
    
    /**
     * Output response
     */
    private function outputResponse() {
        header('Content-Type: text/plain; charset=utf-8');
        if (isset($this->responseData['full_response'])) {
            echo $this->responseData['full_response'];
        } elseif (isset($this->responseData['message'])) {
            echo $this->responseData['message'];
        }
    }
    
    /**
     * Test function to verify parsing
     */
    public function testParsing($testUrl) {
        $_SERVER['QUERY_STRING'] = parse_url($testUrl, PHP_URL_QUERY);
        parse_str($_SERVER['QUERY_STRING'], $_GET);
        
        $result = $this->parseRequest();
        echo "<h3>Test Parsing: " . htmlspecialchars($testUrl) . "</h3>";
        echo "<pre>";
        print_r($result);
        echo "</pre>";
    }
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Main execution
if (php_sapi_name() !== 'cli') {
    $api = new SMMBuffAPI();
    
    // Show test page if requested
    if (isset($_GET['test'])) {
        echo "<!DOCTYPE html><html><head><title>SMM Buff API Test</title></head><body>";
        echo "<h1>🔧 SMM Buff API Testing Tool</h1>";
        
        $api->testParsing('http://localhost/?url=https://t.me/hienios1/3274&quantity=20');
        $api->testParsing('http://localhost/?url=https://t.me/hienios1/3274?quantity=20');
        $api->testParsing('http://localhost/?url=https://t.me/TADMODSCHEATVIP/4071&quantity=50');
        $api->testParsing('http://localhost/?url=https://facebook.com/post123&quantity=100');
        
        echo "<h3>Test Links:</h3>";
        echo "<ul>";
        echo "<li><a href='/?url=https://t.me/hienios1/3274&quantity=20'>/?url=https://t.me/hienios1/3274&quantity=20</a></li>";
        echo "<li><a href='/?url=https://t.me/TADMODSCHEATVIP/4071&quantity=50'>/?url=https://t.me/TADMODSCHEATVIP/4071&quantity=50</a></li>";
        echo "<li><a href='/?url=https://facebook.com/post123&quantity=100'>/?url=https://facebook.com/post123&quantity=100</a></li>";
        echo "</ul>";
        
        echo "</body></html>";
        exit;
    }
    
    $api->handleRequest();
}
?>

<?php
/**
 * Backup SMM API Class (for reference)
 */
class Api {
    public $api_url = 'https://smm-center.com/api/v2';
    public $api_key = '';

    public function order($data) {
        $post = array_merge(['key' => $this->api_key, 'action' => 'add'], $data);
        return json_decode($this->connect($post));
    }

    public function status($order_id) {
        return json_decode($this->connect([
            'key' => $this->api_key,
            'action' => 'status',
            'order' => $order_id
        ]));
    }

    public function multiStatus($order_ids) {
        return json_decode($this->connect([
            'key' => $this->api_key,
            'action' => 'status',
            'orders' => implode(",", (array)$order_ids)
        ]));
    }

    public function services() {
        return json_decode($this->connect([
            'key' => $this->api_key,
            'action' => 'services',
        ]));
    }

    public function refill($orderId) {
        return json_decode($this->connect([
            'key' => $this->api_key,
            'action' => 'refill',
            'order' => $orderId,
        ]));
    }

    public function multiRefill($orderIds) {
        return json_decode($this->connect([
            'key' => $this->api_key,
            'action' => 'refill',
            'orders' => implode(',', $orderIds),
        ]), true);
    }

    public function refillStatus($refillId) {
        return json_decode($this->connect([
            'key' => $this->api_key,
            'action' => 'refill_status',
            'refill' => $refillId,
        ]));
    }

    public function multiRefillStatus($refillIds) {
        return json_decode($this->connect([
            'key' => $this->api_key,
            'action' => 'refill_status',
            'refills' => implode(',', $refillIds),
        ]), true);
    }

    public function cancel($orderIds) {
        return json_decode($this->connect([
            'key' => $this->api_key,
            'action' => 'cancel',
            'orders' => implode(',', $orderIds),
        ]), true);
    }

    public function balance() {
        return json_decode($this->connect([
            'key' => $this->api_key,
            'action' => 'balance',
        ]));
    }

    private function connect($post) {
        $_post = [];
        if (is_array($post)) {
            foreach ($post as $name => $value) {
                $_post[] = $name . '=' . urlencode($value);
            }
        }

        $ch = curl_init($this->api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if (is_array($post)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, join('&', $_post));
        }
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');
        $result = curl_exec($ch);
        if (curl_errno($ch) != 0 && empty($result)) {
            $result = false;
        }
        curl_close($ch);
        return $result;
    }
}
?>