<?php
$config = [
    'app_name' => 'Aidora CRM',
    'base_url' => '/aidora',
    'db' => [
        'host' => 'localhost',
        'name' => '',
        'user' => '',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'firebase_crm_url' => '',
    'firebase_admin_key' => '',
    'template_name' => 'aidora_plantilla',
    'template_language' => 'es_MX',
];
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $extra = require $local;
    if (is_array($extra)) $config = array_replace_recursive($config, $extra);
}
return $config;
