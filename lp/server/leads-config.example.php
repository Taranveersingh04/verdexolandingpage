<?php
// Settings for the leads database and the private /leads/ page. Copy this file to ONE of these places and fill it in:
//   - leads-config.php, one folder ABOVE public_html (best: not reachable from the web), or
//   - public_html/lp-assets/leads-config.php
// The database: in Hostinger, go to Databases > Management, create a MySQL database, and copy its name, user and
// password below. The leads table is created automatically the first time a lead arrives.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
return [
    'db_host' => 'localhost',
    'db_name' => 'u000000000_leads',
    'db_user' => 'u000000000_leads',
    'db_pass' => 'CHANGE-ME',
    // the password for verdexoventures.com/leads/ (long and unique; anyone with it can see every lead)
    'admin_password' => 'CHANGE-ME',
];
