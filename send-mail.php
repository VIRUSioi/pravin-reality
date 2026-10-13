<?php
/**
 * Pravin Realty - lead mailer (Hostinger)
 *
 * Receives leads from the website (visit form, chatbot, list-your-property form)
 * and emails them to your Hostinger mailbox. WhatsApp still opens as before.
 *
 * SETUP (2 minutes):
 *  1. In hPanel > Emails, create a mailbox on your domain, e.g. info@yourdomain.com
 *  2. Put that address in $TO_EMAIL and $FROM_EMAIL below.
 *  3. Upload this file next to index.html (same folder). Done.
 */

$TO_EMAIL   = 'pravinrealty23@gmail.com';   // where leads are delivered
$FROM_EMAIL = 'info@pravinrealty.com';   // must be a mailbox on your own domain
$SITE_NAME  = 'Pravin Realty';
$MAX_PER_HOUR_PER_IP = 20;

// If you forgot to edit the addresses, fall back to info@<your domain>.
$host = preg_replace('/^www\./i', '', $_SERVER['SERVER_NAME'] ?? 'localhost');
if (stripos($TO_EMAIL, 'YOUR-DOMAIN') !== false)   { $TO_EMAIL   = 'info@' . $host; }
if (stripos($FROM_EMAIL, 'YOUR-DOMAIN') !== false) { $FROM_EMAIL = 'info@' . $host; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($code, $ok, $msg = '') {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $msg]);
    exit;
}
function clean($v, $max = 600) {
    $v = is_scalar($v) ? (string)$v : '';
    $v = str_replace(["\r", "\0"], '', $v);
    $c = preg_replace('/[^\P{C}\n\t]/u', '', $v);   // strip control chars
    if ($c === null) { $c = ''; }
    return mb_substr(trim($c), 0, $max);
}
function oneline($v, $max = 200) {
    return trim(preg_replace('/\s+/', ' ', clean($v, $max)));  // safe for headers
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { out(405, false, 'POST only'); }

$raw = file_get_contents('php://input', false, null, 0, 20000);
$d = json_decode($raw, true);
if (!is_array($d)) { out(400, false, 'Bad request'); }

// Honeypot: real visitors leave this empty.
if (!empty($d['website'])) { out(200, true); }

// Simple per-IP rate limit.
$ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rl  = sys_get_temp_dir() . '/pr_rl_' . md5($ip) . '.json';
$now = time();
$hits = [];
if (is_file($rl)) { $hits = json_decode(@file_get_contents($rl), true) ?: []; }
$hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 3600; }));
if (count($hits) >= $MAX_PER_HOUR_PER_IP) { out(429, false, 'Too many requests'); }
$hits[] = $now;
@file_put_contents($rl, json_encode($hits), LOCK_EX);

$labels = [
    'visit-form'    => 'Plan your visit form',
    'chatbot'       => 'Chatbot enquiry',
    'list-property' => 'List your property',
];
$source = $labels[$d['source'] ?? ''] ?? 'Website enquiry';

$names = [
    'name' => 'Name', 'fullname' => 'Name', 'phone' => 'Phone', 'email' => 'Email',
    'interest' => 'Interested in', 'visit_date' => 'Preferred visit date',
    'mode' => 'Buy / Rent / Sell', 'type' => 'Property type wanted', 'budget' => 'Budget',
    'area' => 'Area (sq ft) / preferred area', 'role' => 'Listing as', 'cat' => 'Category',
    'ptype' => 'Property type', 'loc' => 'Location', 'proj' => 'Project / society',
    'price' => 'Price / rent expected', 'furn' => 'Furnishing', 'note' => 'Notes',
];

$rows = [];
foreach ($names as $key => $label) {
    if (!isset($d[$key])) { continue; }
    $val = clean($d[$key]);
    if ($val === '') { continue; }
    $rows[$label] = $val;
}
if (!$rows) { out(400, false, 'Empty'); }

$page = oneline($d['page'] ?? '', 300);
$when = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('d M Y, h:i A') . ' IST';

// Plain text + HTML body
$text = "New lead: $source\nReceived: $when\n\n";
$html = '<div style="font-family:Arial,sans-serif;max-width:560px">'
      . '<h2 style="margin:0 0 4px">New lead: ' . htmlspecialchars($source, ENT_QUOTES, 'UTF-8') . '</h2>'
      . '<p style="color:#666;margin:0 0 16px">Received ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</p>'
      . '<table style="border-collapse:collapse;width:100%">';
foreach ($rows as $label => $val) {
    $text .= "$label: $val\n";
    $html .= '<tr><td style="padding:8px 10px;border:1px solid #ddd;background:#f6f6f6;width:38%"><b>'
           . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</b></td><td style="padding:8px 10px;border:1px solid #ddd">'
           . nl2br(htmlspecialchars($val, ENT_QUOTES, 'UTF-8')) . '</td></tr>';
}
$html .= '</table>';
if ($page !== '') {
    $text .= "\nPage: $page\n";
    $html .= '<p style="color:#888;font-size:12px;margin-top:16px">Page: ' . htmlspecialchars($page, ENT_QUOTES, 'UTF-8') . '</p>';
}
$html .= '</div>';

// Subject: include the name and phone so it is easy to spot in the inbox
$who = $rows['Name'] ?? 'Website visitor';
$subject = '[' . $SITE_NAME . '] ' . $source . ' - ' . oneline($who, 60) . (isset($rows['Phone']) ? ' (' . oneline($rows['Phone'], 20) . ')' : '');

$boundary = 'pr_' . bin2hex(random_bytes(8));
$headers  = 'From: ' . $SITE_NAME . ' <' . $FROM_EMAIL . ">\r\n";
if (!empty($rows['Email']) && filter_var($rows['Email'], FILTER_VALIDATE_EMAIL)) {
    $headers .= 'Reply-To: ' . $rows['Email'] . "\r\n";
}
$headers .= "MIME-Version: 1.0\r\n";
$headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";

$body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$text\r\n";
$body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$html\r\n";
$body .= "--$boundary--";

$sent = @mail($TO_EMAIL, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . $FROM_EMAIL);

if (!$sent) { out(500, false, 'Mail failed'); }
out(200, true);
