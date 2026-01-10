<?php
require "/var/www/html/wp-load.php";
$valid = BWWC__is_gateway_valid_for_use($reason);
var_dump($valid);
if (!$valid) {
    echo $reason, PHP_EOL;
}
PHP
php /var/www/html/check-gateway.php
rm /var/www/html/check-gateway.php
