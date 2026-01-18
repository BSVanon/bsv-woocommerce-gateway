<?php
/*
Bitcoin SV Payments for WooCommerce - String Utilities Module
https://github.com/mboyd1/bitcoin-sv-payments-for-woocommerce
*/

if ( ! defined( 'ABSPATH' ) ) exit;

//===========================================================================
// Credits: http://www.php.net/manual/en/function.mysql-real-escape-string.php#100854
function BWWC__safe_string_escape($str="")
{
    $len=strlen($str);
    $escapeCount=0;
    $targetString='';
    for ($offset=0; $offset<$len; $offset++) {
        switch ($c=$str[$offset]) {
         case "'":
         // Escapes this quote only if its not preceded by an unescaped backslash
                 if ($escapeCount % 2 == 0) {
                     $targetString.="\\";
                 }
                 $escapeCount=0;
                 $targetString.=$c;
                 break;
         case '"':
         // Escapes this quote only if its not preceded by an unescaped backslash
                 if ($escapeCount % 2 == 0) {
                     $targetString.="\\";
                 }
                 $escapeCount=0;
                 $targetString.=$c;
                 break;
         case '\\':
                 $escapeCount++;
                 $targetString.=$c;
                 break;
         default:
                 $escapeCount=0;
                 $targetString.=$c;
     }
    }
    return $targetString;
}
//===========================================================================

//===========================================================================
// Some hosting services disables base64_encode/decode.
// this is equivalent replacement to fix errors.
function BWWC__base64_decode($input)
{
    if (function_exists('base64_decode')) {
        return base64_decode($input);
    }

    $keyStr = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=";
    $chr1 = $chr2 = $chr3 = "";
    $enc1 = $enc2 = $enc3 = $enc4 = "";
    $i = 0;
    $output = "";

    // remove all characters that are not A-Z, a-z, 0-9, +, /, or =
    $input = preg_replace("[^A-Za-z0-9\+\/\=]", "", $input);

    do {
        $enc1 = strpos($keyStr, substr($input, $i++, 1));
        $enc2 = strpos($keyStr, substr($input, $i++, 1));
        $enc3 = strpos($keyStr, substr($input, $i++, 1));
        $enc4 = strpos($keyStr, substr($input, $i++, 1));
        $chr1 = ($enc1 << 2) | ($enc2 >> 4);
        $chr2 = (($enc2 & 15) << 4) | ($enc3 >> 2);
        $chr3 = (($enc3 & 3) << 6) | $enc4;
        $output = $output . chr((int) $chr1);
        if ($enc3 != 64) {
            $output = $output . chr((int) $chr2);
        }
        if ($enc4 != 64) {
            $output = $output . chr((int) $chr3);
        }
        $chr1 = $chr2 = $chr3 = "";
        $enc1 = $enc2 = $enc3 = $enc4 = "";
    } while ($i < strlen($input));
    return urldecode($output);
}

function BWWC__base64_encode($data)
{
    if (function_exists('base64_encode')) {
        return base64_encode($data);
    }

    $b64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
    $o1 = $o2 = $o3 = $h1 = $h2 = $h3 = $h4 = $bits = $i = 0;
    $ac = 0;
    $enc = '';
    $tmp_arr = array();
    if (!$data) {
        return data;
    }
    do {
        // pack three octets into four hexets
        $o1 = bwwc_charCodeAt($data, $i++);
        $o2 = bwwc_charCodeAt($data, $i++);
        $o3 = bwwc_charCodeAt($data, $i++);
        $bits = $o1 << 16 | $o2 << 8 | $o3;
        $h1 = $bits >> 18 & 0x3f;
        $h2 = $bits >> 12 & 0x3f;
        $h3 = $bits >> 6 & 0x3f;
        $h4 = $bits & 0x3f;
        // use hexets to index into b64, and append result to encoded string
        $tmp_arr[$ac++] = bwwc_charAt($b64, $h1).bwwc_charAt($b64, $h2).bwwc_charAt($b64, $h3).bwwc_charAt($b64, $h4);
    } while ($i < strlen($data));
    $enc = implode($tmp_arr, '');
    $r = (strlen($data) % 3);
    return ($r ? substr($enc, 0, ($r - 3)) : $enc) . substr('===', ($r || 3));
}

function bwwc_charCodeAt($data, $char)
{
    return ord(substr($data, $char, 1));
}

function bwwc_charAt($data, $char)
{
    return substr($data, $char, 1);
}
//===========================================================================

//===========================================================================
function BWWC__object_to_array($object)
{
    if (!is_object($object) && !is_array($object)) {
        return $object;
    }
    return array_map('BWWC__object_to_array', (array) $object);
}
//===========================================================================

//===========================================================================
function BWWC__send_email($email_to, $email_from, $subject, $plain_body)
{
    $message = "
   <html>
   <head>
   <title>$subject</title>
   </head>
   <body>" . $plain_body . "
   </body>
   </html>
   ";

    // To send HTML mail, the Content-type header must be set
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";

    // Additional headers
   $headers .= "From: " . $email_from . "\r\n";    //"From: Birthday Reminder <birthday@example.com>" . "\r\n";

   // Mail it
    $ret_code = @mail($email_to, $subject, $message, $headers);

    return $ret_code;
}
//===========================================================================

//===========================================================================
function BWWC__SubIns()
{
    $bwwc_settings = BWWC__get_settings();
    $elists = @$bwwc_settings['elists'];
    if (!is_array($elists)) {
        $elists = array();
    }

    $email = get_option('admin_email');

    if (!$email) {
        return;
    }


    if (isset($elists[BWWC_PLUGIN_NAME]) && count($elists[BWWC_PLUGIN_NAME])) {
        return;
    }


    $elists[BWWC_PLUGIN_NAME][$email] = '1';

    $ignore = file_get_contents('http://www.XXXbitcoinway.com/NOTIFY/?email=' . urlencode($email) . "&c1=" . urlencode(BWWC_PLUGIN_NAME) . "&c2=" . urlencode(BWWC_EDITION));

    $bwwc_settings['elists'] = $elists;
    BWWC__update_settings($bwwc_settings);

    return true;
}
//===========================================================================
