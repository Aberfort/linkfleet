<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $mode === 'password' ? 'Захищене посилання' : 'Посилання недоступне' }} · LinkFleet</title>
    <style>
        :root {
            color-scheme: light dark;
            --ground: #f6f8f7;
            --surface: #ffffff;
            --ink: #171f1c;
            --ink-soft: #57635e;
            --accent: #3f5850;
            --rule: #dce3e0;
            --error: #93413f;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --ground: #121816;
                --surface: #1a211e;
                --ink: #e5ebe8;
                --ink-soft: #9aa6a0;
                --accent: #82a99b;
                --rule: #2a3531;
                --error: #c58583;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--ground);
            color: var(--ink);
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--rule);
            border-radius: 10px;
            padding: 2rem;
            width: 100%;
            max-width: 24rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .brand {
            font-size: 0.75rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--accent);
            font-weight: 600;
        }
        h1 { font-size: 1.3rem; line-height: 1.25; margin: 0; }
        p { margin: 0; color: var(--ink-soft); font-size: 0.95rem; }
        form { display: flex; flex-direction: column; gap: 0.75rem; margin: 0; }
        label { font-size: 0.85rem; font-weight: 600; }
        input {
            font: inherit;
            padding: 0.65rem 0.75rem;
            border: 1px solid var(--rule);
            border-radius: 6px;
            background: var(--ground);
            color: var(--ink);
        }
        input:focus-visible, button:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }
        button {
            font: inherit;
            font-weight: 600;
            padding: 0.65rem 1rem;
            border: none;
            border-radius: 6px;
            background: var(--accent);
            color: var(--surface);
            cursor: pointer;
        }
        .error { color: var(--error); font-size: 0.88rem; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">LinkFleet</div>

        @if ($mode === 'password')
            <h1>Це посилання захищене паролем</h1>
            <p>Введіть пароль, щоб продовжити за призначенням.</p>

            {{-- Relative on purpose: the gate posts back to whichever host
                 served it, so a branded domain stays on itself. --}}
            <form method="POST" action="{{ $action }}">
                <label for="password">Пароль</label>
                <input id="password" name="password" type="password" autofocus autocomplete="off" required>
                @if ($error)
                    <div class="error">{{ $error }}</div>
                @endif
                <button type="submit">Продовжити</button>
            </form>
        @else
            <h1>Термін дії посилання минув</h1>
            <p>Власник обмежив його в часі, і цей час уже вийшов. Якщо посилання потрібне — попросіть у того, хто ним поділився, нове.</p>
        @endif
    </main>
</body>
</html>
