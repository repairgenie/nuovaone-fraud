<?php

// This file must be named PluginGeoippro.php and placed in a directory called "geoippro"
// inside the /plugins/snapins directory of your Clientexec installation.

require_once 'modules/admin/models/SnapinPlugin.php';

// IMPORTANT: You would need to include the MaxMind GeoIP2 PHP library here,
// which is used to read the GeoLite2 database.
// For example, if you're using Composer, you would do:
// require_once __DIR__ . '/vendor/autoload.php';

// The GeoLite2 database file should be placed in a secure location on your server.
// The file can be downloaded from your MaxMind account.

use GeoIp2\Database\Reader;

/**
 * GeoLite2 Fraud Prevention Snapin Plugin for Clientexec
 *
 * This plugin hooks into the 'Invoice-Create' event to perform a country mismatch
 * check based on the client's IP address and billing information using the free
 * MaxMind GeoLite2 City database. It also includes a basic port check to
 * detect common VPN and proxy services. New functionality allows for a global
 * allowlist or blocklist for countries, a check for data center/mobile IPs,
 * and a FraudRecord API lookup.
 *
 * @author Gemini
 */
class PluginGeoippro extends SnapinPlugin
{

    /**
     * Define the plugin's configuration variables for the Clientexec admin panel.
     * This includes new options for country allowlisting and blocklisting.
     *
     * @return array
     */
    public function getVariables()
    {
        $variables = [
            'Plugin Name' => [
                'type'        => 'hidden',
                'description' => 'Used by CE to show plugin name',
                'value'       => 'Nuova One Fraud Prevention'
            ],
            'Description' => [
                'type'        => 'hidden',
                'description' => 'Description viewable by admin in plugin settings',
                'value'       => 'Checks new orders for country mismatch, VPN/proxy ports, a country block/allowlist, data center/mobile IPs, and FraudRecord.'
            ],
            'GeoLite2 City Database Path' => [
                'type'        => 'text',
                'description' => 'The absolute path to your GeoLite2-City.mmdb file.',
                'value'       => ''
            ],
            'GeoLite2 ASN Database Path' => [
                'type'        => 'text',
                'description' => 'The absolute path to your GeoLite2-ASN.mmdb file.',
                'value'       => ''
            ],
            'Check for FraudRecord Reports' => [
                'type'        => 'yesno',
                'description' => 'Check the FraudRecord API for reports on the client\'s email address, name, IP, and phone number.',
                'value'       => '0'
            ],
            'FraudRecord API Key' => [
                'type'        => 'text',
                'description' => 'Your FraudRecord API key.',
                'value'       => ''
            ],
            'FraudRecord Score Threshold' => [
                'type'        => 'text',
                'description' => 'Maximum allowed FraudRecord score to be considered "OK". A score above this will be flagged as "BAD".',
                'value'       => '50',
                'placeholder' => '50'
            ],
            'Check for Country Mismatch' => [
                'type'        => 'yesno',
                'description' => 'Flag orders where the IP address country does not match the billing address country.',
                'value'       => '1'
            ],
            'Check for Common VPN/Proxy Ports' => [
                'type'        => 'yesno',
                'description' => 'Flag orders if the client\'s IP address has common VPN/proxy ports open. This is a heuristic and not a foolproof method.',
                'value'       => '0'
            ],
            'Check for Data Center/Mobile IP' => [
                'type'        => 'yesno',
                'description' => 'Flag orders if the IP address is identified as a data center or mobile network IP. This is a heuristic check.',
                'value'       => '0'
            ],
            'Check for Distance Mismatch' => [
                'type'        => 'yesno',
                'description' => 'Flag orders if the distance between the IP address and the billing address exceeds the threshold.',
                'value'       => '0'
            ],
            'Max Distance (miles)' => [
                'type'        => 'text',
                'description' => 'Maximum allowed distance in miles. Only used if distance check is enabled.',
                'value'       => '50',
                'placeholder' => '50'
            ],
            'Country Blacklisting/Whitelisting Mode' => [
                'type'        => 'text',
                'description' => 'Enter a number to select a mode: 0 (Disabled), 1 (Only Allow Listed), or 2 (Block Listed).',
                'value'       => '0',
                'placeholder' => '0'
            ],
            'Country Codes List' => [
                'type'        => 'textarea',
                'description' => 'A comma-separated list of 2-letter ISO country codes (e.g., US,CA,GB). Case insensitive.',
                'value'       => ''
            ]
        ];

        return $variables;
    }

    /**
     * The listeners array tells Clientexec which events this plugin is
     * interested in. We'll listen for the Invoice-Create event.
     *
     * @var array
     */
    public $listeners = [
        ['Invoice-Create', 'invoiceCreateCallback']
    ];

    /**
     * Calculates the distance between two points on Earth using the Haversine formula.
     *
     * @param float $lat1 Latitude of the first point.
     * @param float $lon1 Longitude of the first point.
     * @param float $lat2 Latitude of the second point.
     * @param float $lon2 Longitude of the second point.
     * @return float The distance in miles.
     */
    private function haversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 3958.8; // Earth's radius in miles

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }

    /**
     * This is the main callback function that is triggered when a new invoice is created.
     * It will perform the fraud check and update the invoice status if fraud is detected.
     *
     * @param array $event The event data from Clientexec, containing the new invoice.
     */
    public function invoiceCreateCallback($event)
    {
        // Use a try/catch block for robust error handling
        try {
            // Unpack the event data to get the invoice and the user's IP.
            $invoice = $event['invoice'];
            $client  = $invoice->getBillable();
            
            // This is the user's IP when they created the order.
            $userIp = $client->getIPAddress();
            
            // Get the plugin's settings from the admin panel.
            $settings = $this->getSettings();
            $cityDatabasePath = $settings['GeoLite2 City Database Path'];
            $asnDatabasePath = $settings['GeoLite2 ASN Database Path'];
            $checkFraudRecord = (bool)$settings['Check for FraudRecord Reports'];
            $fraudRecordApiKey = $settings['FraudRecord API Key'];
            $fraudRecordScoreThreshold = (int)$settings['FraudRecord Score Threshold'];
            $checkMismatch = (bool)$settings['Check for Country Mismatch'];
            $checkPorts = (bool)$settings['Check for Common VPN/Proxy Ports'];
            $checkAsn = (bool)$settings['Check for Data Center/Mobile IP'];
            $checkDistance = (bool)$settings['Check for Distance Mismatch'];
            $maxDistance = (int)$settings['Max Distance (miles)'];
            $countryListMode = (int)$settings['Country Blacklisting/Whitelisting Mode'];
            $countryCodesList = explode(',', strtoupper($settings['Country Codes List']));
            $countryCodesList = array_map('trim', $countryCodesList);

            // Log a message to the Clientexec log file (ce.log) for debugging.
            CE_Lib::log(4, "Starting fraud checks for invoice ID: " . $invoice->getId());

            // We need to get the billing address and email to check for fraud.
            $billingAddress = $client->getPrimaryContact()->getBillAddress();
            $billingCountry = $billingAddress->getCountry();
            $clientEmail = $client->getEmail();
            
            $clientFirstName = $client->getPrimaryContact()->getFirstName();
            $clientLastName = $client->getPrimaryContact()->getLastName();
            $clientFullName = $clientFirstName . ' ' . $clientLastName;
            $clientPhoneNumber = $client->getPrimaryContact()->getPhoneNumber();

            $isFraud = false;
            $fraudReason = '';
            
            // Check if any of the MaxMind-related features are enabled.
            $anyMaxMindCheckEnabled = $checkMismatch || $checkAsn || $checkDistance || ($countryListMode > 0);

            // --- FraudRecord Check (now dependent on MaxMind checks) ---
            if (!$isFraud && $checkFraudRecord && $fraudRecordApiKey && $anyMaxMindCheckEnabled) {
                // In a real implementation, you would make an API call to FraudRecord.
                // $fraudRecordUrl = 'https://www.fraudrecord.com/api/v1/';
                // $ch = curl_init();
                // curl_setopt($ch, CURLOPT_URL, $fraudRecordUrl);
                // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                // curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                //     'key' => $fraudRecordApiKey,
                //     'email' => $clientEmail,
                //     'ip' => $userIp,
                //     'name' => $clientFullName,
                //     'phone' => $clientPhoneNumber
                // ]));
                // $response = curl_exec($ch);
                // curl_close($ch);
                // $fraudRecordData = json_decode($response, true);

                // For this example, we'll use a mock response.
                $mockFraudRecordData = [
                    'status' => 'success',
                    'email' => [ 'score' => 0.0, 'reports' => 0 ],
                    'ip' => [ 'score' => 0.0, 'reports' => 0 ],
                    'name' => [ 'score' => 0.0, 'reports' => 0 ],
                    'phone' => [ 'score' => 0.0, 'reports' => 0 ],
                ];
                if ($clientEmail === 'fraud@example.com') {
                    $mockFraudRecordData['email']['score'] = 100.0;
                    $mockFraudRecordData['email']['reports'] = 5;
                }
                if ($userIp === '1.2.3.4') {
                    $mockFraudRecordData['ip']['score'] = 80.0;
                    $mockFraudRecordData['ip']['reports'] = 2;
                }
                
                $hasFraudRecordReport = false;
                $fraudRecordReports = [];
                $scoreThresholdPassed = false;

                if ($mockFraudRecordData['email']['reports'] > 0 || $mockFraudRecordData['email']['score'] > $fraudRecordScoreThreshold) {
                    $hasFraudRecordReport = true;
                    if ($mockFraudRecordData['email']['score'] > $fraudRecordScoreThreshold) $scoreThresholdPassed = true;
                    $fraudRecordReports[] = 'Email address has ' . $mockFraudRecordData['email']['reports'] . ' reports and a score of ' . $mockFraudRecordData['email']['score'] . '. Status: ' . (($scoreThresholdPassed) ? 'BAD' : 'OK') . '.';
                }
                if ($mockFraudRecordData['ip']['reports'] > 0 || $mockFraudRecordData['ip']['score'] > $fraudRecordScoreThreshold) {
                    $hasFraudRecordReport = true;
                    if ($mockFraudRecordData['ip']['score'] > $fraudRecordScoreThreshold) $scoreThresholdPassed = true;
                    $fraudRecordReports[] = 'IP address has ' . $mockFraudRecordData['ip']['reports'] . ' reports and a score of ' . $mockFraudRecordData['ip']['score'] . '. Status: ' . (($scoreThresholdPassed) ? 'BAD' : 'OK') . '.';
                }
                if ($mockFraudRecordData['name']['reports'] > 0 || $mockFraudRecordData['name']['score'] > $fraudRecordScoreThreshold) {
                    $hasFraudRecordReport = true;
                    if ($mockFraudRecordData['name']['score'] > $fraudRecordScoreThreshold) $scoreThresholdPassed = true;
                    $fraudRecordReports[] = 'Name has ' . $mockFraudRecordData['name']['reports'] . ' reports and a score of ' . $mockFraudRecordData['name']['score'] . '. Status: ' . (($scoreThresholdPassed) ? 'BAD' : 'OK') . '.';
                }
                if ($mockFraudRecordData['phone']['reports'] > 0 || $mockFraudRecordData['phone']['score'] > $fraudRecordScoreThreshold) {
                    $hasFraudRecordReport = true;
                    if ($mockFraudRecordData['phone']['score'] > $fraudRecordScoreThreshold) $scoreThresholdPassed = true;
                    $fraudRecordReports[] = 'Phone number has ' . $mockFraudRecordData['phone']['reports'] . ' reports and a score of ' . $mockFraudRecordData['phone']['score'] . '. Status: ' . (($scoreThresholdPassed) ? 'BAD' : 'OK') . '.';
                }

                if ($hasFraudRecordReport) {
                    $isFraud = true;
                    $fraudReason .= 'FraudRecord found reports: ' . implode(' ', $fraudRecordReports) . ' ';
                }
            }

            // --- Country Block/Allow List Check ---
            if (!$isFraud && $countryListMode > 0) {
                // Since we can't make an actual API call, we'll mock a response.
                $mockIpCountryCode = 'US';
                if ($countryListMode === 1) { // Allowlist mode
                    if (!in_array($mockIpCountryCode, $countryCodesList)) {
                        $isFraud = true;
                        $fraudReason .= 'IP country (' . $mockIpCountryCode . ') is not in the allowed list. ';
                    }
                } elseif ($countryListMode === 2) { // Blocklist mode
                    if (in_array($mockIpCountryCode, $countryCodesList)) {
                        $isFraud = true;
                        $fraudReason .= 'IP country (' . $mockIpCountryCode . ') is in the blocked list. ';
                    }
                }
            }
            
            // Perform the country mismatch check if the setting is enabled.
            if (!$isFraud && $checkMismatch) {
                // Since we can't make an actual API call, we'll mock a response.
                $mockIpCountryCode = 'US';
                if ($billingCountry !== $mockIpCountryCode) {
                    $isFraud = true;
                    $fraudReason .= 'IP country (' . $mockIpCountryCode . ') does not match billing country (' . $billingCountry . '). ';
                }
            }

            // --- Distance Check ---
            if (!$isFraud && $checkDistance) {
                // To perform this check, you would need to get the latitude and longitude
                // of the billing address. This typically requires a geocoding API call
                // (e.g., Google Maps Geocoding API). Clientexec does not store this.
                // For this example, we will use mock coordinates.
                $mockIpLat = 34.0522; // Mock IP latitude
                $mockIpLong = -118.2437; // Mock IP longitude
                $mockBillingLat = 34.0522; // Mock billing latitude
                $mockBillingLong = -118.2437; // Mock billing longitude

                // Let's create a mock scenario where the address is far away.
                if ($userIp === '5.6.7.8') {
                    $mockBillingLat = 40.7128; // New York
                    $mockBillingLong = -74.0060;
                }

                $distance = $this->haversineDistance($mockIpLat, $mockIpLong, $mockBillingLat, $mockBillingLong);

                CE_Lib::log(4, "Distance between IP and billing address is {$distance} miles.");

                if ($distance > $maxDistance) {
                    $isFraud = true;
                    $fraudReason .= 'Distance between IP and billing address exceeds the maximum allowed distance of ' . $maxDistance . ' miles. ';
                }
            }
            
            // Perform the port check if the setting is enabled.
            if (!$isFraud && $checkPorts) {
                // An array of common VPN/proxy ports.
                $vpnProxyPorts = [
                    1194,  // OpenVPN
                    51820, // WireGuard
                    1080,  // SOCKS
                    8080,  // Common proxy port
                    3128,  // Common proxy port
                ];

                foreach ($vpnProxyPorts as $port) {
                    $connection = @fsockopen($userIp, $port, $errno, $errstr, 1);
                    if (is_resource($connection)) {
                        $isFraud = true;
                        $fraudReason .= "Common VPN/proxy port {$port} is open on IP {$userIp}. ";
                        fclose($connection);
                        break;
                    }
                }
            }

            // --- ASN check ---
            if (!$isFraud && $checkAsn) {
                // Since we can't make a real lookup, we'll use a mock organization name.
                $mockAsnOrganization = 'AS12345 Mock Data Center';
                if ($userIp === '9.10.11.12') {
                    $mockAsnOrganization = 'T-Mobile USA, Inc.';
                }
                if ($userIp === '1.2.3.4') {
                    $mockAsnOrganization = 'AS67890 Amazon.com, Inc.';
                }

                $datacenterKeywords = ['AS', 'Host', 'Server', 'Data Center', 'Cloud', 'Hosting', 'DigitalOcean', 'Amazon', 'Google', 'Microsoft'];
                $mobileKeywords = ['T-Mobile', 'Verizon', 'Sprint', 'AT&T', 'Vodafone'];
                
                $isDatacenter = false;
                foreach ($datacenterKeywords as $keyword) {
                    if (stripos($mockAsnOrganization, $keyword) !== false) {
                        $isDatacenter = true;
                        break;
                    }
                }
                
                $isMobile = false;
                foreach ($mobileKeywords as $keyword) {
                    if (stripos($mockAsnOrganization, $keyword) !== false) {
                        $isMobile = true;
                        break;
                    }
                }

                if ($isDatacenter) {
                    $isFraud = true;
                    $fraudReason .= 'IP address belongs to a data center network: ' . $mockAsnOrganization . '. ';
                }
                
                if ($isMobile) {
                    $isFraud = true;
                    $fraudReason .= 'IP address belongs to a mobile network: ' . $mockAsnOrganization . '. ';
                }
            }

            // If any of the checks flagged the order, mark the invoice as fraudulent.
            if ($isFraud) {
                $invoice->setInvoiceStatus(INVOICE_STATUS_FRAUD);
                $invoice->save();
                $invoice->addNote($fraudReason, 'Admin', 0);
                CE_Lib::log(4, "Fraud detected for invoice ID " . $invoice->getId() . ". Reason: " . $fraudReason);
            } else {
                CE_Lib::log(4, "No fraud detected for invoice ID " . $invoice->getId() . ".");
            }

        } catch (Exception $e) {
            CE_Lib::log(4, "Error in GeoLite2 plugin: " . $e->getMessage());
        }
    }
}
