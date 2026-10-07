<?php
session_start();

if (isset($_POST['login'])) {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';

    if ($u === "admin" && $p === "admin123") {
        $_SESSION['admin'] = true;
        header("Location: admin_dashboard.php");
        exit;
    } else {
        $err = "Invalid username or password!";
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    <style>
        :root {
            --bg: #eef2f9;
            --card: #ffffff;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #d5dce8;
            --brand: #0b5ed7;
            --brand-dark: #0847a8;
            --danger: #b42318;
            --danger-bg: #fef3f2;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
        }

        body {
            margin: 0;
            display: grid;
            place-items: center;
            padding: 20px;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 12% 18%, rgba(11, 94, 215, .14), transparent 38%),
                radial-gradient(circle at 88% 82%, rgba(11, 94, 215, .10), transparent 42%),
                var(--bg);
        }

        .box {
            width: 100%;
            max-width: 400px;
            background: var(--card);
            padding: 36px 32px 30px;
            border-radius: 18px;
            border: 1px solid var(--line);
            box-shadow: 0 20px 45px rgba(15, 23, 42, .10);
        }

        .badge {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: var(--brand);
            display: grid;
            place-items: center;
            margin-bottom: 18px;
        }

        .badge svg {
            width: 26px;
            height: 26px;
            stroke: #fff;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        h2 {
            margin: 0 0 6px;
            font-size: 26px;
            letter-spacing: -.02em;
        }

        .sub {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 15px;
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-size: 14px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            color: var(--ink);
            background: #f8fafc;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }

        input:focus {
            outline: none;
            background: #fff;
            border-color: var(--brand);
            box-shadow: 0 0 0 4px rgba(11, 94, 215, .15);
        }

        button {
            width: 100%;
            margin-top: 22px;
            padding: 12px 14px;
            border: 0;
            border-radius: 10px;
            background: var(--brand);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: background .15s, transform .1s;
        }

        button:hover {
            background: var(--brand-dark);
        }

        button:active {
            transform: translateY(1px);
        }

        button:focus-visible,
        a:focus-visible {
            outline: 3px solid rgba(11, 94, 215, .4);
            outline-offset: 2px;
        }

        .err {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid #fecdca;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .back {
            margin: 20px 0 0;
            text-align: center;
            font-size: 14px;
        }

        .back a {
            color: var(--brand);
            text-decoration: none;
            font-weight: 600;
        }

        .back a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="box">
        <div class="badge" aria-hidden="true">
            <svg viewBox="0 0 24 24">
                <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                <path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
            </svg>
        </div>

        <h2>Admin Login</h2>
        <p class="sub">Sign in to manage your dashboard.</p>

        <?php if (!empty($err)): ?>
            <div class="err" role="alert">
                <?= htmlspecialchars($err) ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <label for="username">Username</label>
            <input id="username" name="username" placeholder="Enter username" required autofocus>

            <label for="password">Password</label>
            <input id="password" type="password" name="password" placeholder="Enter password" required>

            <button name="login" type="submit">Login</button>
        </form>

        <p class="back"><a href="index.php">← Back to Home</a></p>
    </div>
</body>

</html>