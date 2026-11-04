<?php
$host     = 'localhost';
$port     = '5432';
$dbname   = 'school_db';      
$user     = 'postgres';      
$password = 'woshidaxuesheng';         

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// CREATE BORROWER + TRANSACTION + ITEM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        $pdo->beginTransaction();

        $stmtB = $pdo->prepare('INSERT INTO borrowers (borrower_no, full_name, borrower_type) VALUES (:borrower_no, :full_name, :borrower_type) RETURNING borrower_id');
        $stmtB->execute([
            ':borrower_no'   => trim($_POST['borrower_no'] ?? ''),
            ':full_name'     => trim($_POST['full_name'] ?? ''),
            ':borrower_type' => trim($_POST['borrower_type'] ?? '')
        ]);
        $borrower_id = $stmtB->fetchColumn();

        $stmtT = $pdo->prepare('INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date, status) VALUES (:borrower_id, :borrow_date, :due_date, :status) RETURNING borrow_id');
        $stmtT->execute([
            ':borrower_id' => $borrower_id,
            ':borrow_date' => $_POST['borrow_date'],
            ':due_date'    => $_POST['due_date'],
            ':status'      => 'Borrowed'
        ]);
        $borrow_id = $stmtT->fetchColumn();

        if (!empty($_POST['equipment_id'])) {
            $stmtI = $pdo->prepare('INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES (:borrow_id, :equipment_id, :quantity)');
            $stmtI->execute([
                ':borrow_id'    => $borrow_id,
                ':equipment_id' => (int)$_POST['equipment_id'],
                ':quantity'     => (int)($_POST['quantity'] ?? 1)
            ]);
        }

        $pdo->commit();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error creating record: ' . $e->getMessage());
    }
}

// UPDATE BORROWER & TRANSACTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    try {
        $pdo->beginTransaction();

        $borrower_id = (int)($_POST['borrower_id'] ?? 0);

        // 1. Update Borrower
        $stmtB = $pdo->prepare('UPDATE borrowers SET borrower_no = :borrower_no, full_name = :full_name, borrower_type = :borrower_type WHERE borrower_id = :borrower_id');
        $stmtB->execute([
            ':borrower_no'   => trim($_POST['borrower_no'] ?? ''),
            ':full_name'     => trim($_POST['full_name'] ?? ''),
            ':borrower_type' => trim($_POST['borrower_type'] ?? ''),
            ':borrower_id'   => $borrower_id
        ]);

        // Fetch existing borrow_id for this borrower (if available)
        $stmtTFetch = $pdo->prepare('SELECT borrow_id FROM borrow_transactions WHERE borrower_id = :borrower_id ORDER BY borrow_id DESC LIMIT 1');
        $stmtTFetch->execute([':borrower_id' => $borrower_id]);
        $borrow_id = $stmtTFetch->fetchColumn();

        if ($borrow_id) {
            // 2. Update Transaction Dates
            $stmtT = $pdo->prepare('UPDATE borrow_transactions SET borrow_date = :borrow_date, due_date = :due_date WHERE borrow_id = :borrow_id');
            $stmtT->execute([
                ':borrow_date' => $_POST['borrow_date'],
                ':due_date'    => $_POST['due_date'],
                ':borrow_id'   => $borrow_id
            ]);

            // 3. Update Borrowed Item
            if (!empty($_POST['equipment_id'])) {
                $stmtI = $pdo->prepare('UPDATE borrow_items SET equipment_id = :equipment_id, quantity = :quantity WHERE borrow_id = :borrow_id');
                $stmtI->execute([
                    ':equipment_id' => (int)$_POST['equipment_id'],
                    ':quantity'     => (int)($_POST['quantity'] ?? 1),
                    ':borrow_id'    => $borrow_id
                ]);
            }
        }

        $pdo->commit();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error updating record: ' . $e->getMessage());
    }
}

// DELETE BORROWER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $pdo->prepare('DELETE FROM borrowers WHERE borrower_id = :borrower_id');
    $stmt->execute([':borrower_id' => (int)($_POST['borrower_id'] ?? 0)]);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// FETCH EQUIPMENT FOR DROPDOWN
$equipment_list = $pdo->query('SELECT equipment_id, equipment_name FROM equipment ORDER BY equipment_name')->fetchAll(PDO::FETCH_ASSOC);

// READ ALL RECORDS
$query = '
    SELECT 
        b.borrower_id,
        b.borrower_no,
        b.full_name,
        b.borrower_type,
        e.equipment_name,
        bi.quantity,
        bt.borrow_date,
        bt.due_date
    FROM borrowers b
    LEFT JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    LEFT JOIN borrow_items bi ON bt.borrow_id = bi.borrow_id
    LEFT JOIN equipment e ON bi.equipment_id = e.equipment_id
    ORDER BY b.borrower_id DESC
';
$records = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Borrower & Transaction CRUD</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-main: #061c12;
            --card-bg: #0f2d21;
            --card-border: #1e3f32;
            --accent: #34d399;
            --accent-hover: #059669;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --danger: #f43f5e;
            --danger-hover: #e11d48;
        }

        * { box-sizing: border-box; }

        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: var(--bg-main);
            color: var(--text-main);
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 40px 20px; 
        }

        .header {
            margin-bottom: 40px;
        }

        .header h1 { 
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(to right, #34d399, #4ade80);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0 0 8px 0;
        }

        .header p {
            color: var(--text-muted);
            margin: 0;
        }

        .card { 
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 32px; 
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
        }

        .card h2 { 
            color: var(--text-main); 
            font-size: 1.25rem; 
            margin-top: 0;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        h4 { 
            color: var(--accent); 
            margin: 16px 0 10px 0; 
            font-weight: 600; 
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            align-items: center;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        label {
            font-weight: 500;
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        input, select { 
            background-color: #061c12;
            color: var(--text-main);
            border: 1px solid var(--card-border);
            padding: 10px 14px; 
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: inherit;
            outline: none;
            transition: all 0.2s ease;
            width: 100%;
        }

        input:focus, select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(52, 211, 153, 0.2);
        }

        button {
            background-color: var(--accent);
            color: #061c12;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 16px;
        }

        button:hover {
            background-color: var(--accent-hover);
            color: #fff;
            transform: translateY(-1px);
        }

        .btn-delete {
            background-color: rgba(244, 63, 94, 0.15);
            color: var(--danger);
            border: 1px solid rgba(244, 63, 94, 0.3);
            padding: 6px 12px;
            font-size: 0.8rem;
            margin-top: 0;
        }

        .btn-delete:hover {
            background-color: var(--danger);
            color: white;
            transform: none;
        }

        .table-container {
            overflow-x: auto;
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
            text-align: left;
        }

        th {
            background-color: rgba(6, 28, 18, 0.6);
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 14px 16px;
            border-bottom: 1px solid var(--card-border);
        }

        td { 
            border-bottom: 1px solid var(--card-border); 
            padding: 14px 16px; 
            color: var(--text-main);
            font-size: 0.9rem;
        }

        tr:hover td {
            background-color: rgba(255, 255, 255, 0.02);
        }

        .badge {
            background: rgba(52, 211, 153, 0.1);
            color: var(--accent);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>Borrower & Transaction Hub</h1>
        <p>Manage equipment checkouts, borrower profiles, and active records.</p>
    </div>

    <!-- CREATE FORM -->
    <div class="card">
        <h2>New Checkout Transaction</h2>
        <form method="post">
            <input type="hidden" name="action" value="create">
            
            <h4>1. Borrower Details</h4>
            <div class="form-grid">
                <input type="text" name="borrower_no" placeholder="Borrower No." required>
                <input type="text" name="full_name" placeholder="Full Name" required>
                <select name="borrower_type" required>
                    <option value="">-- Select Type --</option>
                    <option value="Student">Student</option>
                    <option value="Faculty">Faculty</option>
                </select>
            </div>

            <h4>2. Checkout Details</h4>
            <div class="form-grid">
                <div class="form-group">
                    <label>Equipment</label>
                    <select name="equipment_id" required>
                        <option value="">-- Select Item --</option>
                        <?php foreach ($equipment_list as $item): ?>
                            <option value="<?= $item['equipment_id'] ?>"><?= htmlspecialchars($item['equipment_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" value="1" min="1" required>
                </div>
                <div class="form-group">
                    <label>Borrow Date</label>
                    <input type="date" name="borrow_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Due Date</label>
                    <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" required>
                </div>
            </div>

            <button type="submit">Submit Transaction</button>
        </form>
    </div>

    <!-- RECORDS TABLE -->
    <div class="card">
        <h2>Active Records</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Borrower No.</th>
                        <th>Full Name</th>
                        <th>Type</th>
                        <th>Equipment</th>
                        <th>Qty</th>
                        <th>Borrow Date</th>
                        <th>Due Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $row): ?>
                    <tr>
                        <td><span class="badge">#<?= (int)$row['borrower_id'] ?></span></td>
                        <td><?= htmlspecialchars($row['borrower_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['borrower_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['equipment_name'] ?? 'None', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)($row['quantity'] ?? 0) ?></td>
                        <td><?= htmlspecialchars($row['borrow_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['due_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <form method="post" style="display:inline; margin:0;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="borrower_id" value="<?= (int)$row['borrower_id'] ?>">
                                <button type="submit" class="btn-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- UPDATE FORM -->
    <div class="card">
        <h2>Update Existing Record</h2>
        <form method="post">
            <input type="hidden" name="action" value="update">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Target Borrower ID</label>
                    <input type="number" name="borrower_id" placeholder="e.g. 5" required>
                </div>
                <div class="form-group">
                    <label>Borrower No.</label>
                    <input type="text" name="borrower_no" placeholder="Borrower No." required>
                </div>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" placeholder="Full Name" required>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="borrower_type" required>
                        <option value="">-- Select Type --</option>
                        <option value="Student">Student</option>
                        <option value="Faculty">Faculty</option>
                    </select>
                </div>
            </div>

            <br>

            <div class="form-grid">
                <div class="form-group">
                    <label>Equipment</label>
                    <select name="equipment_id" required>
                        <option value="">-- Select Item --</option>
                        <?php foreach ($equipment_list as $item): ?>
                            <option value="<?= $item['equipment_id'] ?>"><?= htmlspecialchars($item['equipment_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" value="1" min="1" required>
                </div>
                <div class="form-group">
                    <label>Borrow Date</label>
                    <input type="date" name="borrow_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Due Date</label>
                    <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" required>
                </div>
            </div>

            <button type="submit">Update Record</button>
        </form>
    </div>

</body>
</html>