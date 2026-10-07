<?php
require_once "db.php";

$order_id = (int) ($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    die("Invalid order.");
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Demo Payment</title>

    <style>
        body {
            font-family: Arial;
            background: #f4f7fb;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            width: 320px;
            text-align: center;
        }

        button {
            background: #0b5ed7;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
        }

        input {
            width: 100%;
            padding: 8px;
            margin: 8px 0;
            border: 1px solid #ccc;
            border-radius: 6px;
        }
    </style>

</head>

<body>

    <div class="card">

        <h2>Demo Payment</h2>

        <p>This is a demo payment page</p>

        <input placeholder="Card Number">
        <input placeholder="MM/YY">
        <input placeholder="CVV">

        <br>

        <a href="payment_success.php?order_id=<?= $order_id ?>">
            <button>Pay Now</button>
        </a>

    </div>

</body>

</html>