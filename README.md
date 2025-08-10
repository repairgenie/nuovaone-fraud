# Nuova One Fraud Prevention Plugin Documentation

This plugin is provided at no charge, and with no support, by Nuova Webs. For professional installations and general installation queries, you can email concierge@nuovawebs.com

## Installation

1.  **File Placement:** Create a new directory named `geoippro` inside your Clientexec `/plugins/snapins/` directory.

2.  **Plugin File:** Place the provided `PluginGeoippro.php` file inside the newly created `geoippro` directory. The final path should be `/plugins/snapins/geoippro/PluginGeoippro.php`.

3.  **Dependencies:** This plugin requires the MaxMind GeoIP2 PHP library. If you use Composer, you'll need to run `composer require maxmind/geoip2`.

4.  **Database Files:** You must download the `GeoLite2-City.mmdb` and `GeoLite2-ASN.mmdb` database files from your MaxMind account. Place these files in a secure location on your server and note the absolute file paths.

## Configuration

After installation, navigate to **Settings > Plugins > Snapins** in your Clientexec admin panel and find the "Nuova One Fraud Prevention" plugin. Here you can configure the plugin's behavior.

* **GeoLite2 City Database Path:** The absolute path to your `GeoLite2-City.mmdb` file. This is used for country, city, and location lookups.

* **GeoLite2 ASN Database Path:** The absolute path to your `GeoLite2-ASN.mmdb` file. This is used to check for data center or mobile network IPs.

* **Check for FraudRecord Reports:** Set to `Yes` to enable API lookups on client emails, IPs, names, and phone numbers. Requires a FraudRecord API key.

* **FraudRecord API Key:** Your API key from FraudRecord.

* **FraudRecord Score Threshold:** The maximum allowed score. Any score above this will flag the order as fraudulent. Default is 50.

* **Check for Country Mismatch:** Set to `Yes` to flag orders where the client's IP address country doesn't match their billing address country.

* **Check for Common VPN/Proxy Ports:** A heuristic check to flag IPs that have common VPN/proxy ports open. Note the presence of these ports being open isn't the end-all for determining actual proxy usage. A more advanced check will be required (such as via nmap) to determine if there is such a proxy/vpn server actually running on these ports. This is due to many ISP end-user devices having some of these ports open by default (and unclosable) due to most likely being used for management.

* **Check for Data Center/Mobile IP:** Set to `Yes` to check if the client's IP belongs to a data center or mobile network using the GeoLite2 ASN database.

* **Check for Distance Mismatch:** Set to `Yes` to check if the distance between the IP and the billing address exceeds a threshold.

* **Max Distance (miles):** The maximum allowed distance in miles for the distance check. Default is 50.

* **Country Blacklisting/Whitelisting Mode:** Set to `0` (Disabled), `1` (Allow Listed), or `2` (Block Listed).

* **Country Codes List:** A comma-separated list of 2-letter ISO country codes (e.g., `US,CA,GB`). Used with the blacklist/whitelist mode.

## Basic Troubleshooting

* **Plugin does not appear in the admin panel:**

  * Ensure the file is named `PluginGeoippro.php` exactly as specified.

  * Verify that the directory structure is correct: `/plugins/snapins/geoippro/`.

* **Fraud checks are not working as expected:**

  * Check your Clientexec log file (`ce.log`) for any errors. The plugin writes log messages that can help diagnose issues.

  * Verify that the absolute paths for your MaxMind database files are correct in the plugin settings.

  * Remember that the provided PHP code contains many mocked functions (e.g., for FraudRecord, port checks, etc.) to simulate the real behavior. To use the full functionality, you would need to uncomment and implement the real API calls.

* **A specific check is failing (e.g., FraudRecord):**

  * Double-check that the feature is enabled in the configuration and that the necessary API key or file path is correctly entered.

  * Again, check the `ce.log` file for specific error messages from that part of the code.