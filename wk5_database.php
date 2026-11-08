<?php
session_start();

$host     = 'localhost';
$port     = '5432';
$dbname   = 'postgres';      
$user     = 'postgres';      
$password = 'woshidaxuesheng';         

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Helper for flash messages
function setFlash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
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
        setFlash("Borrower and transaction added successfully!");
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash("Error creating record: " . $e->getMessage(), 'error');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// EQUIPMENT CRUD OPERATIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Create Equipment
    if ($action === 'create_equipment') {
        try {
            $stmt = $pdo->prepare('INSERT INTO equipment (property_no, equipment_name, quantity_available) VALUES (:property_no, :equipment_name, :quantity_available)');
            $stmt->execute([
                ':property_no'        => trim($_POST['property_no'] ?? ''),
                ':equipment_name'     => trim($_POST['equipment_name'] ?? ''),
                ':quantity_available' => (int)($_POST['quantity_available'] ?? 0)
            ]);
            setFlash("Equipment added successfully!");
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } catch (Exception $e) {
            setFlash("Error adding equipment: " . $e->getMessage(), 'error');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    
    // Update Equipment
    if ($action === 'update_equipment') {
        try {
            $stmt = $pdo->prepare('UPDATE equipment SET property_no = :property_no, equipment_name = :equipment_name, quantity_available = :quantity_available WHERE equipment_id = :equipment_id');
            $stmt->execute([
                ':property_no'        => trim($_POST['property_no'] ?? ''),
                ':equipment_name'     => trim($_POST['equipment_name'] ?? ''),
                ':quantity_available' => (int)($_POST['quantity_available'] ?? 0),
                ':equipment_id'       => (int)$_POST['equipment_id']
            ]);
            setFlash("Equipment updated successfully!");
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } catch (Exception $e) {
            setFlash("Error updating equipment: " . $e->getMessage(), 'error');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    
    // Delete Equipment
    if ($action === 'delete_equipment') {
        try {
            $stmt = $pdo->prepare('DELETE FROM equipment WHERE equipment_id = :equipment_id');
            $stmt->execute([':equipment_id' => (int)$_POST['equipment_id']]);
            setFlash("Equipment deleted successfully!");
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } catch (Exception $e) {
            setFlash("Error deleting equipment: " . $e->getMessage(), 'error');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
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
        setFlash("Record updated successfully!");
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash("Error updating record: " . $e->getMessage(), 'error');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// DELETE BORROWER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    try {
        $pdo->beginTransaction();
        $borrower_id = (int)($_POST['borrower_id'] ?? 0);
        
        $stmtT = $pdo->prepare('SELECT borrow_id FROM borrow_transactions WHERE borrower_id = :id');
        $stmtT->execute([':id' => $borrower_id]);
        $transactions = $stmtT->fetchAll(PDO::FETCH_COLUMN);

        foreach ($transactions as $bid) {
            $pdo->prepare('DELETE FROM borrow_items WHERE borrow_id = :bid')->execute([':bid' => $bid]);
            $pdo->prepare('DELETE FROM borrow_transactions WHERE borrow_id = :bid')->execute([':bid' => $bid]);
        }
        
        $pdo->prepare('DELETE FROM borrowers WHERE borrower_id = :id')->execute([':id' => $borrower_id]);
        
        $pdo->commit();
        setFlash("Borrower deleted successfully!");
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash("Error deleting borrower: " . $e->getMessage(), 'error');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// CREATE OR REPLACE VIEWS (Rubric Criteria)
try {
    // View 1: Detailed Borrowing Information
    $pdo->exec("
        CREATE OR REPLACE VIEW vw_borrowing_details AS
        SELECT 
            b.borrower_no, 
            b.full_name, 
            e.equipment_name, 
            bi.quantity, 
            bt.borrow_date, 
            bt.due_date, 
            bt.status
        FROM borrowers b
        JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
        JOIN borrow_items bi ON bt.borrow_id = bi.borrow_id
        JOIN equipment e ON bi.equipment_id = e.equipment_id
    ");

    // View 2: Equipment Usage and Status Summary
    $pdo->exec("
        CREATE OR REPLACE VIEW vw_equipment_usage AS
        SELECT 
            e.equipment_id,
            e.property_no,
            e.equipment_name,
            COUNT(bi.borrow_id) AS total_transactions,
            SUM(COALESCE(bi.quantity, 0)) AS total_quantity_borrowed,
            CASE 
                WHEN e.quantity_available > 5 THEN 'Available'
                WHEN e.quantity_available BETWEEN 1 AND 5 THEN 'Low Stock'
                ELSE 'Out of Stock'
            END AS availability_status
        FROM equipment e
        LEFT JOIN borrow_items bi ON e.equipment_id = bi.equipment_id
        GROUP BY e.equipment_id, e.property_no, e.equipment_name
    ");
} catch (Exception $e) {
    // Silently fail if permissions don't allow view creation
}

// WEEK 10 POSTGRESQL DATABASE OBJECTS (Functions, Triggers, Audit)
try {
    // 1. SQL Function: fn_total_borrowed
    $pdo->exec("
        CREATE OR REPLACE FUNCTION fn_total_borrowed(p_equipment_id INT)
        RETURNS INT AS $$
        BEGIN
            RETURN (SELECT COALESCE(SUM(quantity), 0) FROM borrow_items WHERE equipment_id = p_equipment_id);
        END;
        $$ LANGUAGE plpgsql;
    ");

    // 2. PL/pgSQL Function: fn_check_equipment_availability
    $pdo->exec("
        CREATE OR REPLACE FUNCTION fn_check_equipment_availability(p_equipment_id INT, p_requested_qty INT)
        RETURNS BOOLEAN AS $$
        DECLARE
            v_available_qty INT;
        BEGIN
            SELECT quantity_available INTO v_available_qty FROM equipment WHERE equipment_id = p_equipment_id;
            IF v_available_qty >= p_requested_qty THEN
                RETURN TRUE;
            ELSE
                RETURN FALSE;
            END IF;
        END;
        $$ LANGUAGE plpgsql;
    ");

    // 3. BEFORE Validation Trigger: trg_validate_borrow_quantity
    $pdo->exec("
        CREATE OR REPLACE FUNCTION fn_validate_borrow_quantity()
        RETURNS TRIGGER AS $$
        DECLARE
            v_available_qty INT;
        BEGIN
            SELECT quantity_available INTO v_available_qty FROM equipment WHERE equipment_id = NEW.equipment_id;
            IF NEW.quantity > v_available_qty THEN
                RAISE EXCEPTION 'Requested quantity (%) exceeds available stock (%)', NEW.quantity, v_available_qty;
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;

        DROP TRIGGER IF EXISTS trg_validate_borrow_quantity ON borrow_items;
        CREATE TRIGGER trg_validate_borrow_quantity
        BEFORE INSERT OR UPDATE ON borrow_items
        FOR EACH ROW EXECUTE FUNCTION fn_validate_borrow_quantity();
    ");

    // 4. Audit Table & AFTER Audit Trigger
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS borrow_items_audit (
            audit_id SERIAL PRIMARY KEY,
            borrow_id INT,
            equipment_id INT,
            quantity INT,
            action VARCHAR(10),
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE OR REPLACE FUNCTION fn_borrow_items_audit()
        RETURNS TRIGGER AS $$
        BEGIN
            IF (TG_OP = 'INSERT') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, action)
                VALUES (NEW.borrow_id, NEW.equipment_id, NEW.quantity, 'INSERT');
            ELSIF (TG_OP = 'UPDATE') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, action)
                VALUES (NEW.borrow_id, NEW.equipment_id, NEW.quantity, 'UPDATE');
            ELSIF (TG_OP = 'DELETE') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, action)
                VALUES (OLD.borrow_id, OLD.equipment_id, OLD.quantity, 'DELETE');
            END IF;
            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;

        DROP TRIGGER IF EXISTS trg_borrow_items_audit ON borrow_items;
        CREATE TRIGGER trg_borrow_items_audit
        AFTER INSERT OR UPDATE OR DELETE ON borrow_items
        FOR EACH ROW EXECUTE FUNCTION fn_borrow_items_audit();
    ");
} catch (Exception $e) {
    // Silently fail if permissions don't allow object creation
}

// FETCH EQUIPMENT FOR DROPDOWN & TABLE
$equipment_list = $pdo->query('SELECT equipment_id, property_no, equipment_name, quantity_available FROM equipment ORDER BY equipment_name')->fetchAll(PDO::FETCH_ASSOC);

// Backend query using DISTINCT for Rubric Criteria 6
$distinct_types = $pdo->query("SELECT DISTINCT borrower_type AS unique_role FROM borrowers WHERE borrower_type IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

// Demonstrating UNION (removes duplicate names across borrowers and equipment) 
$all_unique_names = $pdo->query("SELECT full_name AS entity_name FROM borrowers UNION SELECT equipment_name AS entity_name FROM equipment")->fetchAll(PDO::FETCH_COLUMN); 

// Equipment Without Borrowing History Report (Rubric Criteria)
$unborrowed_equipment = $pdo->query("SELECT e.property_no, e.equipment_name FROM equipment e LEFT JOIN borrow_items bi ON e.equipment_id = bi.equipment_id WHERE bi.borrow_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);

// Monthly Borrowing Report using date formatting functions (Rubric Criteria)
$monthly_report = $pdo->query("SELECT TO_CHAR(bt.borrow_date, 'YYYY-MM (Month)') AS month_year, COUNT(bt.borrow_id) AS total_borrows FROM borrow_transactions bt GROUP BY month_year ORDER BY month_year DESC")->fetchAll(PDO::FETCH_ASSOC);

// --- 1. DATA RETRIEVAL, FILTERING, & SORTING ---
$search_name = $_GET['search_name'] ?? '';
$filter_type = $_GET['filter_type'] ?? '';
$sort_by     = $_GET['sort_by'] ?? 'b.borrower_id';
$sort_dir    = $_GET['sort_dir'] ?? 'DESC';

// Allowed sort columns for safety
$allowed_columns = ['b.borrower_id', 'b.full_name', 'bt.borrow_date', 'bt.due_date'];
if (!in_array($sort_by, $allowed_columns)) $sort_by = 'b.borrower_id';
if (!in_array($sort_dir, ['ASC', 'DESC'])) $sort_dir = 'DESC';

// --- 2. JOINS, SQL FUNCTIONS, & CONDITIONAL SQL ---
// Using UPPER(), COALESCE(), and CASE expressions
$query = "
    SELECT 
        b.borrower_id AS id, b.borrower_id AS borrower_id,
        b.borrower_no AS student_no, b.borrower_no AS borrower_no,
        UPPER(b.full_name) AS full_name, 
        b.borrower_type AS role, b.borrower_type AS borrower_type,
        COALESCE(e.equipment_name, 'N/A') AS equipment_name,
        bi.quantity AS quantity,
        bt.borrow_date AS borrow_date,
        bt.due_date AS due_date,
        ABS(CURRENT_DATE - bt.due_date) AS days_difference,
        CASE 
            WHEN bt.due_date < CURRENT_DATE THEN 'Overdue'
            ELSE 'On Time'
        END AS status_check
    FROM borrowers b
    LEFT JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    LEFT JOIN borrow_items bi ON bt.borrow_id = bi.borrow_id
    LEFT JOIN equipment e ON bi.equipment_id = e.equipment_id
    WHERE 1=1
";

$params = [];
if ($search_name) {
    $query .= " AND b.full_name ILIKE :search";
    $params[':search'] = "%$search_name%";
}
if ($filter_type) {
    $query .= " AND b.borrower_type = :type";
    $params[':type'] = $filter_type;
}

$query .= " ORDER BY $sort_by $sort_dir, b.full_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. AGGREGATION, GROUPING, & SET OPERATIONS ---
// Using COUNT(), SUM(), AVG(), MIN(), MAX(), GROUP BY, HAVING, and UNION ALL
$summary_query = "
    (SELECT 
        b.borrower_type, 
        COUNT(b.borrower_id) as total_borrowers, 
        SUM(COALESCE(bi.quantity, 0)) as total_items, 
        ROUND(AVG(COALESCE(bi.quantity, 0)), 1) as avg_items, 
        MIN(COALESCE(bi.quantity, 0)) as min_items, 
        MAX(COALESCE(bi.quantity, 0)) as max_items 
    FROM borrowers b 
    LEFT JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id 
    LEFT JOIN borrow_items bi ON bt.borrow_id = bi.borrow_id 
    GROUP BY b.borrower_type 
    HAVING COUNT(b.borrower_id) >= 1)
    
    UNION ALL
    
    -- Demonstrating SET OPERATIONS (UNION) for a Total Row
    (SELECT 
        'OVERALL TOTAL' as borrower_type,
        COUNT(b.borrower_id) as total_borrowers,
        SUM(COALESCE(bi.quantity, 0)) as total_items,
        ROUND(AVG(COALESCE(bi.quantity, 0)), 1) as avg_items,
        MIN(COALESCE(bi.quantity, 0)) as min_items,
        MAX(COALESCE(bi.quantity, 0)) as max_items
    FROM borrowers b
    LEFT JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    LEFT JOIN borrow_items bi ON bt.borrow_id = bi.borrow_id)
    ORDER BY total_items ASC
";
$summary_records = $pdo->query($summary_query)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Advanced Borrower & Transaction CRUD</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 1100px; margin: 30px auto; padding: 0 20px; background-color: #f4f7f6; }
        h1, h2 { color: #333; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        form { margin-bottom: 0; }
        input, select, button { padding: 10px; margin: 5px 0; border: 1px solid #ddd; border-radius: 4px; }
        button { background-color: #007bff; color: white; border: none; cursor: pointer; transition: background 0.3s; }
        button:hover { background-color: #0056b3; }
        button.delete { background-color: #dc3545; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border-bottom: 1px solid #eee; padding: 12px; text-align: left; }
        th { background-color: #f8f9fa; font-weight: 600; }
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 0.85em; }
        .badge-overdue { background: #ffdada; color: #a94442; }
         .badge-ontime { background: #d4edda; color: #155724; }
         .summary-box { display: flex; gap: 20px; }
         .summary-item { flex: 1; text-align: center; }

         /* Alert Styles */
         .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
         .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
         .alert-error { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
         
         /* Quick Action Styles */
         .btn-edit { background-color: #ffc107; color: #212529; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
         .btn-edit:hover { background-color: #e0a800; }
         .action-group { display: flex; gap: 5px; }
     </style>
 </head>
 <body>
     <h1>Borrower & Transaction Management</h1>

     <?php 
     if (isset($_SESSION['flash'])) {
         $flash = $_SESSION['flash'];
         echo "<div class='alert alert-{$flash['type']}'>{$flash['msg']}</div>";
         unset($_SESSION['flash']);
     }
     ?>

    <!-- Summary Section (Grouping & Aggregation) -->
    <div class="card">
        <h2>System Summary (Grouped by Type)</h2>
        <div class="summary-box">
            <?php foreach ($summary_records as $sum): ?>
            <div class="summary-item">
                <strong><?= htmlspecialchars($sum['borrower_type']) ?></strong><br>
                Borrowers: <?= $sum['total_borrowers'] ?> | Items: <?= $sum['total_items'] ?><br>
                Avg: <?= $sum['avg_items'] ?> | Min: <?= $sum['min_items'] ?> | Max: <?= $sum['max_items'] ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Filter & Sort Section -->
    <div class="card">
        <h2>Search & Filter</h2>
        <form method="get">
            <input type="text" name="search_name" placeholder="Search by name..." value="<?= htmlspecialchars($search_name) ?>">
            <select name="filter_type">
                <option value="">-- All Types --</option>
                <option value="Student" <?= $filter_type === 'Student' ? 'selected' : '' ?>>Student</option>
                <option value="Faculty" <?= $filter_type === 'Faculty' ? 'selected' : '' ?>>Faculty</option>
            </select>
            <select name="sort_by">
                <option value="b.borrower_id" <?= $sort_by === 'b.borrower_id' ? 'selected' : '' ?>>Sort by ID</option>
                <option value="b.full_name" <?= $sort_by === 'b.full_name' ? 'selected' : '' ?>>Sort by Name</option>
                <option value="bt.borrow_date" <?= $sort_by === 'bt.borrow_date' ? 'selected' : '' ?>>Sort by Date</option>
            </select>
            <select name="sort_dir">
                <option value="DESC" <?= $sort_dir === 'DESC' ? 'selected' : '' ?>>Descending</option>
                <option value="ASC" <?= $sort_dir === 'ASC' ? 'selected' : '' ?>>Ascending</option>
            </select>
            <button type="submit">Apply Filter</button>
            <a href="<?= $_SERVER['PHP_SELF'] ?>"><button type="button" style="background:#6c757d">Reset</button></a>
        </form>
    </div>

    <div class="card">
        <h2>Create Borrower & Checkout</h2>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                <input type="text" name="borrower_no" placeholder="Borrower No." required>
                <input type="text" name="full_name" placeholder="Full Name" required>
                <select name="borrower_type" required>
                    <option value="">-- Select Type --</option>
                    <option value="Student">Student</option>
                    <option value="Faculty">Faculty</option>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px; margin-top: 10px;">
                <select name="equipment_id" required>
                    <option value="">-- Equipment --</option>
                    <?php foreach ($equipment_list as $item): ?>
                        <option value="<?= $item['equipment_id'] ?>"><?= htmlspecialchars($item['equipment_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="quantity" placeholder="Qty" value="1" min="1" required>
                <input type="date" name="borrow_date" value="<?= date('Y-m-d') ?>" required>
                <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" required>
            </div>
            <button type="submit" style="margin-top: 10px; width: 100%;">Add Record</button>
        </form>
    </div>

    <div class="card">
        <h2>Records</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Borrower No.</th>
                    <th>Full Name</th>
                    <th>Type</th>
                    <th>Equipment</th>
                    <th>Qty</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $row): ?>
                <tr>
                    <td><?= (int)$row['borrower_id'] ?></td>
                    <td><?= htmlspecialchars($row['borrower_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['borrower_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['equipment_name'] ?? 'None', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($row['quantity'] ?? 0) ?></td>
                    <td><?= htmlspecialchars($row['borrow_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <span class="badge badge-<?= $row['status_check'] === 'Overdue' ? 'overdue' : 'ontime' ?>">
                            <?= $row['status_check'] ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-group">
                            <button type="button" class="btn-edit" 
                                onclick="fillBorrowerUpdate('<?= (int)$row['borrower_id'] ?>', '<?= htmlspecialchars($row['borrower_no'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['full_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['borrower_type'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['borrow_date'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['due_date'], ENT_QUOTES) ?>')">
                                Edit
                            </button>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="borrower_id" value="<?= (int)$row['borrower_id'] ?>">
                                <button type="submit" class="delete" onclick="return confirm('Are you sure?')">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card" id="borrower-update-card">
        <h2>Update Record</h2>
        <form method="post">
            <input type="hidden" name="action" value="update">
            <div style="display: grid; grid-template-columns: 0.5fr 1fr 1.5fr 1fr; gap: 10px;">
                <input type="number" name="borrower_id" id="upd_borrower_id" placeholder="ID" readonly required>
                <input type="text" name="borrower_no" id="upd_borrower_no" placeholder="No." required>
                <input type="text" name="full_name" id="upd_full_name" placeholder="Name" required>
                <select name="borrower_type" id="upd_borrower_type" required>
                    <option value="">-- Type --</option>
                    <option value="Student">Student</option>
                    <option value="Faculty">Faculty</option>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px; margin-top: 10px;">
                <select name="equipment_id" required>
                    <option value="">-- Equipment --</option>
                    <?php foreach ($equipment_list as $item): ?>
                        <option value="<?= $item['equipment_id'] ?>"><?= htmlspecialchars($item['equipment_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="quantity" placeholder="Qty" value="1" min="1" required>
                <input type="date" name="borrow_date" id="upd_borrow_date" value="<?= date('Y-m-d') ?>" required>
                <input type="date" name="due_date" id="upd_due_date" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" required>
            </div>
            <button type="submit" style="margin-top: 10px; width: 100%; background-color: #28a745;">Update Record</button>
        </form>
    </div>

    <hr>
    <h1>Equipment Management</h1>

    <div class="card">
        <h2>Add Equipment</h2>
        <form method="post">
            <input type="hidden" name="action" value="create_equipment">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                <input type="text" name="property_no" placeholder="Property No." required>
                <input type="text" name="equipment_name" placeholder="Equipment Name" required>
                <input type="number" name="quantity_available" placeholder="Available Quantity" min="0" required>
            </div>
            <button type="submit" style="margin-top: 10px; width: 100%;">Add Equipment</button>
        </form>
    </div>

    <div class="card">
        <h2>Equipment Inventory</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Property No.</th>
                    <th>Name</th>
                    <th>Qty Available</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($equipment_list as $eq): ?>
                <tr>
                    <td><?= (int)$eq['equipment_id'] ?></td>
                    <td><?= htmlspecialchars($eq['property_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($eq['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)$eq['quantity_available'] ?></td>
                    <td>
                        <div class="action-group">
                            <button type="button" class="btn-edit" 
                                onclick="fillEquipmentUpdate('<?= (int)$eq['equipment_id'] ?>', '<?= htmlspecialchars($eq['property_no'], ENT_QUOTES) ?>', '<?= htmlspecialchars($eq['equipment_name'], ENT_QUOTES) ?>', '<?= (int)$eq['quantity_available'] ?>')">
                                Edit
                            </button>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="delete_equipment">
                                <input type="hidden" name="equipment_id" value="<?= (int)$eq['equipment_id'] ?>">
                                <button type="submit" class="delete" onclick="return confirm('Are you sure you want to delete this equipment?')">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card" id="equipment-update-card">
        <h2>Update Equipment</h2>
        <form method="post" id="equipment-update-form">
            <input type="hidden" name="action" value="update_equipment">
            <div style="display: grid; grid-template-columns: 0.5fr 1fr 1.5fr 1fr; gap: 10px;">
                <input type="number" name="equipment_id" id="upd_eq_id" placeholder="ID" readonly required>
                <input type="text" name="property_no" id="upd_eq_prop" placeholder="Property No." required>
                <input type="text" name="equipment_name" id="upd_eq_name" placeholder="Name" required>
                <input type="number" name="quantity_available" id="upd_eq_qty" placeholder="Qty" min="0" required>
            </div>
            <button type="submit" style="margin-top: 10px; width: 100%; background-color: #28a745;">Update Equipment</button>
        </form>
    </div>

    <script>
        function fillBorrowerUpdate(id, no, name, type, borrowDate, dueDate) {
            document.getElementById('upd_borrower_id').value = id;
            document.getElementById('upd_borrower_no').value = no;
            document.getElementById('upd_full_name').value = name;
            document.getElementById('upd_borrower_type').value = type;
            document.getElementById('upd_borrow_date').value = borrowDate;
            document.getElementById('upd_due_date').value = dueDate;
            
            document.getElementById('borrower-update-card').scrollIntoView({ behavior: 'smooth' });
        }

        function fillEquipmentUpdate(id, prop, name, qty) {
            document.getElementById('upd_eq_id').value = id;
            document.getElementById('upd_eq_prop').value = prop;
            document.getElementById('upd_eq_name').value = name;
            document.getElementById('upd_eq_qty').value = qty;
            
            document.getElementById('equipment-update-card').scrollIntoView({ behavior: 'smooth' });
        }
    </script>
</body>
</html>
</body>
</html>