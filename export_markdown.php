<?php
ini_set('display_errors', '0');

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$PGHOST = getenv('PGHOST') ?: 'localhost';
$PGPORT = getenv('PGPORT') ?: '5432';
$PGDATABASE = getenv('PGDATABASE') ?: 'journals';
$PGUSER = getenv('PGUSER') ?: 'journal_user';
$PGPASSWORD = getenv('PGPASSWORD') ?: '';

$selectedPub = isset($_GET['publication']) && is_string($_GET['publication']) ? trim($_GET['publication']) : '';
$selectedYear = isset($_GET['year']) && is_string($_GET['year']) ? trim($_GET['year']) : '';
$selectedMonth = isset($_GET['month']) && is_string($_GET['month']) ? trim($_GET['month']) : 'all';

$monthOptions = [
    'all' => 'Full Year',
    '01' => 'January',
    '02' => 'February',
    '03' => 'March',
    '04' => 'April',
    '05' => 'May',
    '06' => 'June',
    '07' => 'July',
    '08' => 'August',
    '09' => 'September',
    '10' => 'October',
    '11' => 'November',
    '12' => 'December',
];

try {
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $PGHOST, $PGPORT, $PGDATABASE);
    $pdo = new PDO($dsn, $PGUSER, $PGPASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database connection failed.</h1>';
    exit;
}

$publications = [];
$years = [];

try {
    $pubStmt = $pdo->query("SELECT DISTINCT pubname FROM docs WHERE pubname IS NOT NULL ORDER BY pubname ASC");
    $publications = $pubStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $yearStmt = $pdo->query("SELECT DISTINCT EXTRACT(YEAR FROM date)::int AS year FROM docs WHERE date IS NOT NULL ORDER BY year DESC");
    $years = $yearStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    // Ignore for now; selections will be empty
}

$markdown = null;
$resultRows = [];
$errorMessage = '';

if ($selectedPub !== '' && $selectedYear !== '') {
    if (!in_array($selectedPub, $publications, true)) {
        $errorMessage = 'Selected publication is not available.';
    } elseif (!in_array((int)$selectedYear, array_map('intval', $years), true)) {
        $errorMessage = 'Selected year is not available.';
    } else {
        $yearInt = (int)$selectedYear;
        $startDate = sprintf('%04d-01-01', $yearInt);
        $endDate = sprintf('%04d-01-01', $yearInt + 1);

        if ($selectedMonth !== 'all' && isset($monthOptions[$selectedMonth])) {
            $monthInt = (int)$selectedMonth;
            $startDate = sprintf('%04d-%02d-01', $yearInt, $monthInt);
            if ($monthInt === 12) {
                $endDate = sprintf('%04d-01-01', $yearInt + 1);
            } else {
                $endDate = sprintf('%04d-%02d-01', $yearInt, $monthInt + 1);
            }
        } else {
            $selectedMonth = 'all';
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT pubname, date, summary_clean, meta->>'title' AS title FROM docs " .
                "WHERE pubname = :pub AND date >= :start AND date < :end " .
                "AND summary_clean IS NOT NULL ORDER BY date ASC"
            );
            $stmt->execute([
                ':pub' => $selectedPub,
                ':start' => $startDate,
                ':end' => $endDate,
            ]);
            $resultRows = $stmt->fetchAll();

            if ($resultRows) {
                $lines = [];
                foreach ($resultRows as $row) {
                    $pub = $row['pubname'] ?? '';
                    $dateValue = $row['date'] ?? null;
                    $summary = trim((string)($row['summary_clean'] ?? ''));
                    $title = trim((string)($row['title'] ?? ''));
                    if ($summary === '') {
                        continue;
                    }
                    $dateStr = '';
                    if ($dateValue !== null) {
                        $dateObj = new DateTime($dateValue);
                        $dateStr = $dateObj->format('Y-m-d');
                    }
                    $lines[] = sprintf('## %s (%s, London UK)', $pub, $dateStr);
                    $lines[] = '';
                    if ($title !== '') {
                        $lines[] = '### ' . $title;
                    }
                    $lines[] = $summary;
                    $lines[] = '';
                    $lines[] = '---';
                    $lines[] = '';
                }
                if ($lines) {
                    $markdown = rtrim(implode("\n", $lines)) . "\n";
                } else {
                    $markdown = '';
                }
            } else {
                $markdown = '';
            }
        } catch (Throwable $e) {
            $errorMessage = 'Failed to load export data.';
        }
    }
}

if ($markdown !== null && isset($_GET['download']) && $_GET['download'] === '1') {
    $safePub = preg_replace('/[^A-Za-z0-9_-]+/', '-', $selectedPub);
    $label = $selectedMonth === 'all' ? $selectedYear : ($selectedYear . '-' . $selectedMonth);
    $filename = strtolower(trim($safePub . '-' . $label, '-')) ?: 'export';

    header('Content-Type: text/markdown; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.md"');
    echo $markdown;
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Markdown Export</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            margin: 2rem;
            color: #1f2933;
            background-color: #f9fbfd;
        }
        h1 {
            margin-bottom: 1.5rem;
        }
        form {
            display: grid;
            gap: 1rem;
            max-width: 480px;
            padding: 1.5rem;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        label {
            font-weight: 600;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        select, button {
            font-size: 1rem;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
        }
        button {
            background-color: #2563eb;
            color: #ffffff;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s ease-in-out;
        }
        button:hover {
            background-color: #1d4ed8;
        }
        .results {
            margin-top: 2rem;
        }
        textarea {
            width: 100%;
            min-height: 320px;
            font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
            font-size: 0.95rem;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background-color: #fff;
            box-sizing: border-box;
        }
        .actions {
            display: flex;
            gap: 0.75rem;
        }
        .actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 0.75rem;
            background-color: #10b981;
            color: #ffffff;
            border-radius: 6px;
            text-decoration: none;
        }
        .error {
            margin-top: 1rem;
            color: #b91c1c;
            font-weight: 600;
        }
        @media (max-width: 600px) {
            body {
                margin: 1rem;
            }
            form {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <h1>Export Summaries (Markdown)</h1>
    <form method="get" action="">
        <label>
            Publication
            <select name="publication" required>
                <option value="">Select publication</option>
                <?php foreach ($publications as $pub): ?>
                    <option value="<?= h($pub) ?>" <?= $selectedPub === $pub ? 'selected' : '' ?>><?= h($pub) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Year
            <select name="year" required>
                <option value="">Select year</option>
                <?php foreach ($years as $year): ?>
                    <?php $yearStr = (string)$year; ?>
                    <option value="<?= h($yearStr) ?>" <?= $selectedYear === $yearStr ? 'selected' : '' ?>><?= h($yearStr) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Month
            <select name="month">
                <?php foreach ($monthOptions as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $selectedMonth === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="actions">
            <button type="submit">Generate Markdown</button>
            <?php if ($markdown !== null && $markdown !== '' && $errorMessage === ''): ?>
                <?php
                $downloadParams = [
                    'publication' => $selectedPub,
                    'year' => $selectedYear,
                    'month' => $selectedMonth,
                    'download' => '1',
                ];
                $downloadUrl = '?' . http_build_query($downloadParams);
                ?>
                <a href="<?= h($downloadUrl) ?>">Download</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($errorMessage !== ''): ?>
        <div class="error"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($markdown !== null && $errorMessage === ''): ?>
        <div class="results">
            <h2>Markdown Output</h2>
            <?php if ($markdown === ''): ?>
                <p>No summaries found for the selected filters.</p>
            <?php else: ?>
                <textarea readonly><?= h($markdown) ?></textarea>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>
