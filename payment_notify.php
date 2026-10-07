<?php
require_once "db.php";

/* =========================
   PayHere Config
========================= */
$PAYHERE_MERCHANT_ID = "YOUR_PAYHERE_MERCHANT_ID";
$PAYHERE_MERCHANT_SECRET = "YOUR_PAYHERE_MERCHANT_SECRET";

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $rs && $rs->num_rows > 0;
}

function postv(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

$merchant_id = postv('merchant_id');
$order_id = (int) postv('order_id');
$payment_id = postv('payment_id');
$payhere_amount = postv('payhere_amount');
$payhere_currency = postv('payhere_currency');
$status_code = postv('status_code');
$md5sig = postv('md5sig');
$method = postv('method');
$status_message = postv('status_message');

if ($order_id <= 0) {
    http_response_code(400);
    exit("Invalid order id");
}

/* verify merchant id */
if ($merchant_id !== $PAYHERE_MERCHANT_ID) {
    http_response_code(400);
    exit("Invalid merchant");
}

/* verify signature */
$local_md5sig = strtoupper(
    md5(
        $merchant_id .
        $order_id .
        $payhere_amount .
        $payhere_currency .
        $status_code .
        strtoupper(md5($PAYHERE_MERCHANT_SECRET))
    )
);

if ($local_md5sig !== $md5sig) {
    http_response_code(400);
    exit("Invalid signature");
}

/* status mapping
   2 success, 0 pending, -1 canceled, -2 failed, -3 chargedback
*/
$paymentStatus = 'Pending';
$orderStatus = 'Pending';

if ($status_code === '2') {
    $paymentStatus = 'Paid';
    $orderStatus = 'Paid';
} elseif ($status_code === '0') {
    $paymentStatus = 'Pending';
    $orderStatus = 'Pending';
} elseif ($status_code === '-1') {
    $paymentStatus = 'Cancelled';
    $orderStatus = 'Cancelled';
} elseif ($status_code === '-2') {
    $paymentStatus = 'Failed';
    $orderStatus = 'Failed';
} elseif ($status_code === '-3') {
    $paymentStatus = 'Chargeback';
    $orderStatus = 'Chargeback';
}

/* build dynamic update based on columns present */
$set = [];
$params = [];
$types = '';

if (column_exists($conn, 'orders', 'payment_status')) {
    $set[] = "payment_status=?";
    $types .= 's';
    $params[] = $paymentStatus;
}

if (column_exists($conn, 'orders', 'payment_id')) {
    $set[] = "payment_id=?";
    $types .= 's';
    $params[] = $payment_id;
}

if (column_exists($conn, 'orders', 'payment_method')) {
    $set[] = "payment_method=?";
    $types .= 's';
    $params[] = ($method !== '' ? $method : 'PayHere');
}

if (column_exists($conn, 'orders', 'payment_amount')) {
    $set[] = "payment_amount=?";
    $types .= 'd';
    $params[] = (float) $payhere_amount;
}

if (column_exists($conn, 'orders', 'payment_currency')) {
    $set[] = "payment_currency=?";
    $types .= 's';
    $params[] = ($payhere_currency !== '' ? $payhere_currency : 'LKR');
}

if (column_exists($conn, 'orders', 'payment_ref')) {
    $set[] = "payment_ref=?";
    $types .= 's';
    $params[] = $status_message;
}

$set[] = "status=?";
$types .= 's';
$params[] = $orderStatus;

if (column_exists($conn, 'orders', 'updated_at')) {
    $set[] = "updated_at=NOW()";
}

$params[] = $order_id;
$types .= 'i';

$sql = "UPDATE orders SET " . implode(', ', $set) . " WHERE id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

http_response_code(200);
echo "OK";