<?php
require_once "db.php";

$msg = "";
$msgType = "error";

// Keep typed values on error
$old = ["name" => "", "email" => "", "phone" => ""];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    $old = compact("name", "email", "phone");

    if ($name === "" || $email === "" || $password === "" || $confirm_password === "") {
        $msg = "Please fill all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $msg = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm_password) {
        $msg = "Passwords do not match.";
    } else {
        $check = $conn->prepare("SELECT id FROM customers WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $msg = "This email is already registered.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO customers (name, email, phone, password) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $name, $email, $phone, $hashed);

            if ($stmt->execute()) {
                header("Location: login.php?registered=success");
                exit;
            } else {
                $msg = "Something went wrong. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Create Account</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b5ed7;
            --card: #ffffff;
            --border: #d5dce8;
            --primary: #0b5ed7;
            --primary-dark: #0847a8;
            --text: #0f172a;
            --muted: #64748b;
            --field: #f8fafc;
            --err-bg: #fef3f2;
            --err-bd: #fecdca;
            --err-text: #b42318;
        }

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            overflow-x: hidden;
        }

        /* ── SOFT BACKGROUND BLOBS ── */
        .mesh {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
        }

        .b1 {
            width: 480px;
            height: 480px;
            background: rgba(255, 255, 255, .18);
            top: -180px;
            left: -140px;
        }

        .b2 {
            width: 420px;
            height: 420px;
            background: rgba(255, 255, 255, .10);
            bottom: -160px;
            right: -120px;
        }

        .b3 {
            display: none;
        }

        /* ── CARD ── */
        .card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 460px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 40px 36px 34px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, .10);
        }

        /* ── HEADER ── */
        .card-top {
            margin-bottom: 26px;
        }

        .logo-ring {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            margin-bottom: 18px;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .card-top h1 {
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -.02em;
            margin-bottom: 6px;
        }

        .card-top p {
            font-size: 15px;
            color: var(--muted);
        }

        /* ── ERROR BOX ── */
        .err {
            display: flex;
            align-items: center;
            gap: 9px;
            background: var(--err-bg);
            border: 1px solid var(--err-bd);
            color: var(--err-text);
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
        }

        /* ── SECTION DIVIDER ── */
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .divider::before {
            display: none;
        }

        /* ── FIELD ── */
        .row2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .field {
            margin-bottom: 16px;
        }

        .field:last-of-type {
            margin-bottom: 0;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .inp-wrap {
            position: relative;
        }

        input {
            width: 100%;
            padding: 12px 14px;
            background: var(--field);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 15px;
            font-family: inherit;
            outline: none;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }

        input::placeholder {
            color: #94a3b8;
        }

        input:focus {
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(11, 94, 215, .15);
        }

        /* eye toggle */
        .eye {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--muted);
            font-size: 15px;
            padding: 6px;
            border-radius: 8px;
            transition: background .15s;
            line-height: 1;
        }

        .eye:hover {
            background: #e8edf5;
        }

        .eye:focus-visible,
        .submit-btn:focus-visible,
        .card-foot a:focus-visible {
            outline: 3px solid rgba(11, 94, 215, .4);
            outline-offset: 2px;
        }

        input[type="password"].has-eye,
        input[type="text"].has-eye {
            padding-right: 44px;
        }

        /* password strength bar */
        .strength-bar {
            height: 4px;
            border-radius: 999px;
            background: #e2e8f0;
            margin-top: 8px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            border-radius: 999px;
            width: 0;
            transition: width .3s, background .3s;
        }

        .strength-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            margin-top: 4px;
            min-height: 16px;
        }

        /* ── SUBMIT ── */
        .submit-btn {
            width: 100%;
            padding: 13px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            margin-top: 22px;
            transition: background .15s, transform .1s;
        }

        .submit-btn:hover {
            background: var(--primary-dark);
        }

        .submit-btn:active {
            transform: translateY(1px);
        }

        /* ── FOOTER LINKS ── */
        .card-foot {
            margin-top: 22px;
            text-align: center;
        }

        .card-foot p {
            font-size: 14px;
            color: var(--muted);
            margin-bottom: 10px;
        }

        .card-foot a.link {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }

        .card-foot a.link:hover {
            text-decoration: underline;
        }

        .card-foot a.back {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            color: var(--muted);
            text-decoration: none;
        }

        .card-foot a.back:hover {
            color: var(--text);
            text-decoration: underline;
        }

        /* ── RESPONSIVE ── */
        @media(max-width:480px) {
            .card {
                padding: 30px 20px;
            }

            .row2 {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }
    </style>
</head>

<body>

    <div class="mesh">
        <div class="blob b1"></div>
        <div class="blob b2"></div>
        <div class="blob b3"></div>
    </div>

    <div class="card">

        <div class="card-top">
            <div class="logo-ring">💻</div>
            <h1>Create Account</h1>
            <p>Register to start shopping for laptops</p>
        </div>

        <?php if ($msg !== ""): ?>
            <div class="err">
                <span>⚠️</span>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="divider">Personal Details</div>

        <form method="POST" action="" novalidate>

            <!-- Name + Phone -->
            <div class="row2">
                <div class="field">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" placeholder="John Doe"
                        value="<?php echo htmlspecialchars($old['name']); ?>" autocomplete="name" required>
                </div>
                <div class="field">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" placeholder="+94 77 000 0000"
                        value="<?php echo htmlspecialchars($old['phone']); ?>" autocomplete="tel">
                </div>
            </div>

            <!-- Email -->
            <div class="field">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="you@example.com"
                    value="<?php echo htmlspecialchars($old['email']); ?>" autocomplete="email" required>
            </div>

            <div class="divider">Security</div>

            <!-- Password -->
            <div class="field">
                <label for="password">Password</label>
                <div class="inp-wrap">
                    <input type="password" id="password" name="password" placeholder="Min. 6 characters" class="has-eye"
                        autocomplete="new-password" required>
                    <button type="button" class="eye" data-target="password">👁</button>
                </div>
                <div class="strength-bar">
                    <div class="strength-fill" id="sBar"></div>
                </div>
                <div class="strength-label" id="sLabel"></div>
            </div>

            <!-- Confirm Password -->
            <div class="field">
                <label for="confirm_password">Confirm Password</label>
                <div class="inp-wrap">
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password"
                        class="has-eye" autocomplete="new-password" required>
                    <button type="button" class="eye" data-target="confirm_password">👁</button>
                </div>
            </div>

            <button type="submit" class="submit-btn">Create Account →</button>
        </form>

        <div class="card-foot">
            <p>Already have an account? <a href="login.php" class="link">Sign In</a></p>
            <a href="index.php" class="back">← Back to Home</a>
        </div>

    </div>

    <script>
        /* ── Eye toggle ── */
        document.querySelectorAll('.eye').forEach(btn => {
            btn.addEventListener('click', () => {
                const inp = document.getElementById(btn.dataset.target);
                const show = inp.type === 'password';
                inp.type = show ? 'text' : 'password';
                btn.textContent = show ? '🙈' : '👁';
            });
        });

        /* ── Password strength ── */
        const pwInp = document.getElementById('password');
        const sBar = document.getElementById('sBar');
        const sLabel = document.getElementById('sLabel');

        const levels = [
            { max: 0, color: '', label: '', w: '0%' },
            { max: 1, color: '#EF4444', label: 'Very Weak', w: '20%' },
            { max: 2, color: '#F97316', label: 'Weak', w: '40%' },
            { max: 3, color: '#EAB308', label: 'Fair', w: '60%' },
            { max: 4, color: '#22C55E', label: 'Strong', w: '80%' },
            { max: 5, color: '#22D3EE', label: 'Very Strong', w: '100%' },
        ];

        function score(pw) {
            let s = 0;
            if (pw.length >= 6) s++;
            if (pw.length >= 10) s++;
            if (/[A-Z]/.test(pw)) s++;
            if (/[0-9]/.test(pw)) s++;
            if (/[^A-Za-z0-9]/.test(pw)) s++;
            return s;
        }

        pwInp.addEventListener('input', () => {
            const pw = pwInp.value;
            if (!pw) { sBar.style.width = '0'; sLabel.textContent = ''; return; }
            const lv = levels[Math.min(score(pw), 5)];
            sBar.style.width = lv.w;
            sBar.style.background = lv.color;
            sLabel.textContent = lv.label;
            sLabel.style.color = lv.color;
        });
    </script>
</body>

</html>