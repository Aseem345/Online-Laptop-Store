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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --primary: #5b8cff;
            --primary-2: #7c5cff;
            --primary-dark: #3d63d9;
            --text: #0f172a;
            --muted: #64748b;
            --border: rgba(255, 255, 255, .55);
            --field: rgba(248, 250, 252, .88);
            --danger-bg: rgba(254, 242, 242, .94);
            --danger-border: #fecaca;
            --danger-text: #b91c1c;
        }

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
            background: #050b18;
        }

        body {
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, "Segoe UI", sans-serif;
            color: #fff;
            overflow-x: hidden;
            background:
                linear-gradient(115deg, rgba(3, 10, 26, .78) 0%, rgba(8, 24, 54, .56) 42%, rgba(4, 12, 32, .70) 100%),
                url('https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=2200&q=90') center center / cover no-repeat fixed;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background:
                radial-gradient(circle at 18% 20%, rgba(65, 137, 255, .28), transparent 34%),
                radial-gradient(circle at 82% 72%, rgba(119, 86, 255, .20), transparent 34%),
                linear-gradient(180deg, rgba(0, 0, 0, .04), rgba(0, 0, 0, .24));
        }

        body::after {
            content: "";
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            opacity: .13;
            background-image:
                linear-gradient(rgba(255, 255, 255, .05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .05) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(to bottom, rgba(0, 0, 0, .7), transparent 75%);
        }

        .register-shell {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 34px clamp(28px, 5vw, 84px);
        }

        /* ===== Background content ===== */
        .visual-side {
            position: absolute;
            inset: 0;
            z-index: 1;
            pointer-events: none;
        }

        .visual-badge {
            position: absolute;
            top: 38px;
            left: clamp(28px, 5vw, 76px);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 999px;
            background: rgba(5, 15, 36, .34);
            color: #fff;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, .18);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .02em;
        }

        .visual-badge span {
            width: 31px;
            height: 31px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(91, 140, 255, .92), rgba(124, 92, 255, .92));
            box-shadow: 0 8px 18px rgba(65, 105, 225, .28);
            font-size: 15px;
        }

        .visual-content {
            position: absolute;
            left: clamp(30px, 6vw, 92px);
            bottom: clamp(42px, 7vh, 92px);
            width: min(640px, 43vw);
            color: #fff;
        }

        .visual-kicker {
            margin-bottom: 15px;
            color: #cfe1ff;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .18em;
            text-transform: uppercase;
        }

        .visual-content h2 {
            max-width: 640px;
            font-size: clamp(42px, 5vw, 74px);
            line-height: 1.02;
            font-weight: 800;
            letter-spacing: -.05em;
            text-shadow: 0 10px 35px rgba(0, 0, 0, .30);
            text-wrap: balance;
        }

        .visual-content p {
            max-width: 520px;
            margin-top: 18px;
            color: rgba(255, 255, 255, .78);
            font-size: 14px;
            line-height: 1.8;
            font-weight: 500;
            text-shadow: 0 4px 18px rgba(0, 0, 0, .28);
        }

        .visual-points {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-top: 25px;
        }

        .visual-point {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 11px;
            border: 1px solid rgba(255, 255, 255, .16);
            border-radius: 11px;
            background: rgba(7, 18, 42, .28);
            color: rgba(255, 255, 255, .9);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            font-size: 10.5px;
            font-weight: 700;
        }

        .visual-point i {
            width: 18px;
            height: 18px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: rgba(96, 165, 250, .22);
            color: #dbeafe;
            font-style: normal;
            font-size: 9px;
        }

        /* ===== Corner form ===== */
        .form-side {
            position: relative;
            z-index: 3;
            width: min(100%, 510px);
            min-height: auto;
            display: block;
            padding: 0;
            background: transparent;
        }

        .form-wrap {
            width: 100%;
            max-width: 510px;
        }

        .card {
            width: 100%;
            padding: 30px 30px 24px;
            border: 1px solid rgba(255, 255, 255, .56);
            border-radius: 24px;
            background:
                linear-gradient(145deg, rgba(255, 255, 255, .96), rgba(244, 248, 255, .92));
            box-shadow:
                0 30px 80px rgba(2, 8, 23, .36),
                inset 0 1px 0 rgba(255, 255, 255, .9);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
        }

        .card-top {
            margin-bottom: 24px;
        }

        .logo-ring {
            width: 52px;
            height: 52px;
            display: grid;
            place-items: center;
            margin-bottom: 17px;
            border-radius: 15px;
            color: #fff;
            background: linear-gradient(135deg, #4d8dff 0%, #5f74ff 48%, #7d5cff 100%);
            box-shadow: 0 14px 30px rgba(84, 111, 255, .30);
            font-size: 23px;
        }

        .card-top h1 {
            margin: 0 0 7px;
            color: #0f172a;
            font-size: 29px;
            line-height: 1.15;
            font-weight: 800;
            letter-spacing: -.04em;
        }

        .card-top p {
            color: #667085;
            font-size: 13px;
            line-height: 1.6;
            font-weight: 500;
        }

        .err {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-bottom: 18px;
            padding: 11px 13px;
            color: var(--danger-text);
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            border-radius: 11px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.5;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 11px;
            margin: 3px 0 14px;
            color: #475467;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .02em;
        }

        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #dfe6ef;
        }

        .divider::before {
            display: none;
        }

        .row2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .field {
            margin-bottom: 14px;
        }

        .field:last-of-type {
            margin-bottom: 0;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: #1f2937;
            font-size: 11.5px;
            font-weight: 700;
        }

        .inp-wrap {
            position: relative;
        }

        input {
            width: 100%;
            min-height: 46px;
            padding: 11px 13px;
            color: #0f172a;
            background: rgba(248, 250, 252, .92);
            border: 1px solid #d8e1ed;
            border-radius: 11px;
            outline: none;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 500;
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease, transform .2s ease;
        }

        input::placeholder {
            color: #9aa8bb;
        }

        input:focus {
            background: #fff;
            border-color: #6f92ff;
            box-shadow: 0 0 0 4px rgba(91, 140, 255, .11);
            transform: translateY(-1px);
        }

        .eye {
            position: absolute;
            right: 7px;
            top: 50%;
            transform: translateY(-50%);
            width: 33px;
            height: 33px;
            display: grid;
            place-items: center;
            border: 0;
            border-radius: 9px;
            color: #667085;
            background: transparent;
            cursor: pointer;
            font-size: 13px;
            transition: background .2s ease, color .2s ease;
        }

        .eye:hover {
            color: #5d74f7;
            background: #edf2ff;
        }

        .eye:focus-visible,
        .submit-btn:focus-visible,
        .card-foot a:focus-visible {
            outline: 3px solid rgba(91, 140, 255, .23);
            outline-offset: 2px;
        }

        input[type="password"].has-eye,
        input[type="text"].has-eye {
            padding-right: 46px;
        }

        .strength-bar {
            height: 4px;
            margin-top: 7px;
            overflow: hidden;
            border-radius: 999px;
            background: #e5eaf1;
        }

        .strength-fill {
            width: 0;
            height: 100%;
            border-radius: 999px;
            transition: width .3s ease, background .3s ease;
        }

        .strength-label {
            min-height: 14px;
            margin-top: 4px;
            color: #667085;
            font-size: 10.5px;
            font-weight: 700;
        }

        .submit-btn {
            width: 100%;
            min-height: 49px;
            margin-top: 18px;
            border: 0;
            border-radius: 11px;
            color: #fff;
            background: linear-gradient(135deg, #4f8cff 0%, #5d72ff 52%, #7657f5 100%);
            box-shadow: 0 14px 26px rgba(85, 105, 255, .25);
            cursor: pointer;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 800;
            letter-spacing: .01em;
            transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(85, 105, 255, .34);
            filter: brightness(1.04);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .card-foot {
            margin-top: 18px;
            text-align: center;
        }

        .card-foot p {
            margin-bottom: 9px;
            color: #667085;
            font-size: 11.5px;
            font-weight: 500;
        }

        .card-foot a.link {
            color: #526ff5;
            font-weight: 800;
            text-decoration: none;
        }

        .card-foot a.link:hover {
            text-decoration: underline;
        }

        .card-foot a.back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #667085;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
        }

        .card-foot a.back:hover {
            color: #526ff5;
        }

        /* ===== Responsive: same full-background + corner card setup ===== */
        @media (max-width: 1100px) {
            .register-shell {
                padding: 30px 28px;
            }

            .visual-content {
                width: min(500px, 43vw);
                left: 36px;
                bottom: 50px;
            }

            .visual-content h2 {
                font-size: clamp(36px, 5vw, 54px);
            }

            .form-side {
                width: min(100%, 470px);
            }

            .form-wrap {
                max-width: 470px;
            }
        }

        @media (max-width: 860px) {
            body {
                background-position: 42% center;
                background-attachment: scroll;
            }

            .register-shell {
                min-height: 100svh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 142px 16px 24px;
            }

            .visual-badge {
                top: 16px;
                left: 16px;
                padding: 8px 11px;
                font-size: 10.5px;
            }

            .visual-badge span {
                width: 27px;
                height: 27px;
                font-size: 13px;
            }

            .visual-content {
                top: 66px;
                left: 18px;
                bottom: auto;
                width: calc(100% - 36px);
                max-width: 460px;
            }

            .visual-kicker {
                margin-bottom: 7px;
                font-size: 9.5px;
                line-height: 1.35;
                letter-spacing: .12em;
            }

            .visual-content h2 {
                max-width: 360px;
                font-size: clamp(24px, 7vw, 32px);
                line-height: 1.14;
                letter-spacing: -.025em;
                word-spacing: .02em;
                text-wrap: balance;
                text-shadow: 0 4px 18px rgba(0, 0, 0, .55);
            }

            .visual-content p,
            .visual-points {
                display: none;
            }

            .form-side,
            .form-wrap {
                width: min(100%, 500px);
                max-width: 500px;
                margin: auto;
            }

            .card {
                width: 100%;
                padding: 26px 22px 21px;
                border-radius: 21px;
                background: linear-gradient(145deg, rgba(255, 255, 255, .97), rgba(245, 248, 255, .94));
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
            }
        }

        @media (max-width: 560px) {
            body {
                background-position: 44% center;
            }

            .register-shell {
                min-height: 100svh;
                align-items: center;
                justify-content: center;
                padding: 128px 12px 18px;
            }

            .visual-badge {
                top: 13px;
                left: 12px;
            }

            .visual-content {
                top: 59px;
                left: 14px;
                width: calc(100% - 28px);
            }

            .visual-kicker {
                margin-bottom: 5px;
                font-size: 9px;
                line-height: 1.35;
                letter-spacing: .10em;
            }

            .visual-content h2 {
                max-width: 295px;
                font-size: clamp(21px, 7vw, 25px);
                line-height: 1.17;
                letter-spacing: -.018em;
                word-spacing: .03em;
                text-wrap: balance;
                text-shadow: 0 4px 18px rgba(0, 0, 0, .60);
            }

            .form-side,
            .form-wrap {
                width: calc(100vw - 24px);
                max-width: 470px;
                margin: auto;
            }

            .card {
                width: 100%;
                padding: 21px 18px 18px;
                border-radius: 20px;
            }

            .card-top {
                margin-bottom: 18px;
            }

            .logo-ring {
                width: 44px;
                height: 44px;
                margin-bottom: 12px;
                border-radius: 13px;
                font-size: 19px;
            }

            .card-top h1 {
                font-size: 24px;
                line-height: 1.2;
            }

            .card-top p {
                font-size: 11.5px;
                line-height: 1.5;
            }

            .divider {
                margin-bottom: 11px;
                font-size: 10.5px;
                line-height: 1.4;
            }

            .row2 {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .field {
                margin-bottom: 11px;
            }

            label {
                font-size: 11px;
                line-height: 1.4;
            }

            input {
                min-height: 42px;
                padding: 10px 12px;
                font-size: 12px;
                line-height: 1.4;
            }

            .eye {
                width: 31px;
                height: 31px;
            }

            .strength-label {
                min-height: 12px;
                font-size: 10px;
            }

            .submit-btn {
                min-height: 45px;
                margin-top: 14px;
            }

            .card-foot {
                margin-top: 15px;
            }

            .card-foot p,
            .card-foot a.back {
                line-height: 1.5;
            }
        }

        @media (max-width: 560px) and (max-height: 760px) {
            .register-shell {
                align-items: flex-start;
                justify-content: center;
                padding-top: 122px;
                padding-bottom: 12px;
            }

            .card {
                padding-top: 18px;
            }

            .card-top {
                margin-bottom: 15px;
            }

            .field {
                margin-bottom: 9px;
            }

            .submit-btn {
                margin-top: 11px;
            }
        }

        @media (max-height: 760px) and (min-width: 861px) {
            .register-shell {
                align-items: flex-start;
                padding-top: 22px;
                padding-bottom: 22px;
            }

            .card {
                padding-top: 24px;
                padding-bottom: 20px;
            }

            .card-top {
                margin-bottom: 18px;
            }

            .field {
                margin-bottom: 11px;
            }

            .visual-content {
                bottom: 34px;
            }
        }
    </style>
</head>

<body>

    <main class="register-shell">
        <section class="visual-side">
            <div class="visual-badge"><span>💻</span>Online Laptop Store</div>
            <div class="visual-content">
                <div class="visual-kicker">Your next laptop starts here</div>
                <h2>Find the right laptop for the way you work.</h2>
                <p>Create your account to explore laptops, save favourites, track orders and manage your purchases in
                    one place.</p>
                <div class="visual-points">
                    <div class="visual-point"><i>✓</i> Smart shopping</div>
                    <div class="visual-point"><i>✓</i> Order tracking</div>
                    <div class="visual-point"><i>✓</i> Easy returns</div>
                </div>
            </div>
        </section>

        <section class="form-side">
            <div class="form-wrap">
                <div class="card">
                    <div class="card-top">
                        <div class="logo-ring">💻</div>
                        <h1>Create your account</h1>
                        <p>Join us and start shopping for your next laptop.</p>
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
                                <input type="password" id="password" name="password" placeholder="Min. 6 characters"
                                    class="has-eye" autocomplete="new-password" required>
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
                                <input type="password" id="confirm_password" name="confirm_password"
                                    placeholder="Re-enter password" class="has-eye" autocomplete="new-password"
                                    required>
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
            </div>
        </section>
    </main>

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