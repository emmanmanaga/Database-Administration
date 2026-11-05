<?php
session_start();

$host     = '10.102.249.250';   // Remote PostgreSQL server (separate physical PC)
$port     = '5432';
$dbname   = 'postgres';      
$user     = 'postgres';      
$password = 'maura';         

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname;connect_timeout=5", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Helper for flash messages
function setFlash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

// Translate raw PostgreSQL errors into readable, user-friendly messages
function friendlyError(Throwable $e) {
    $map = [
        '23505' => 'Duplicate value — that identifier already exists. Please use a unique one.',
        '23503' => 'Invalid reference — the selected borrower or equipment does not exist.',
        '23514' => 'Invalid value — it breaks an allowed range or list rule.',
        '23502' => 'A required field was left empty.',
        '22P02' => 'Invalid input format.',
        '22007' => 'Invalid date format.',
    ];
    $code = $e->getCode();
    if (isset($map[$code])) {
        return $map[$code];
    }
    // Trigger RAISE EXCEPTION messages are already human-readable
    if (preg_match('/ERROR:\s*(.+)$/s', $e->getMessage(), $m)) {
        return trim($m[1]);
    }
    return $e->getMessage();
}

// CREATE BORROWER + TRANSACTION + ITEM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        $pdo->beginTransaction();

        $borrower_no   = trim($_POST['borrower_no'] ?? '');
        $full_name     = trim($_POST['full_name'] ?? '');
        $borrower_type = strtolower(trim($_POST['borrower_type'] ?? ''));

        // Merge: reuse the borrower if the Borrower No. already exists
        $stmtFind = $pdo->prepare('SELECT borrower_id FROM borrowers WHERE borrower_no = :borrower_no');
        $stmtFind->execute([':borrower_no' => $borrower_no]);
        $borrower_id = $stmtFind->fetchColumn();

        if ($borrower_id) {
            $stmtB = $pdo->prepare('UPDATE borrowers SET full_name = :full_name, borrower_type = :borrower_type WHERE borrower_id = :borrower_id');
            $stmtB->execute([
                ':full_name'     => $full_name,
                ':borrower_type' => $borrower_type,
                ':borrower_id'   => $borrower_id
            ]);
            $merged = true;
        } else {
            $stmtB = $pdo->prepare('INSERT INTO borrowers (borrower_no, full_name, borrower_type) VALUES (:borrower_no, :full_name, :borrower_type) RETURNING borrower_id');
            $stmtB->execute([
                ':borrower_no'   => $borrower_no,
                ':full_name'     => $full_name,
                ':borrower_type' => $borrower_type
            ]);
            $borrower_id = $stmtB->fetchColumn();
            $merged = false;
        }

        $stmtT = $pdo->prepare('INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date, status) VALUES (:borrower_id, :borrow_date, :due_date, :status) RETURNING borrow_id');
        $stmtT->execute([
            ':borrower_id' => $borrower_id,
            ':borrow_date' => $_POST['borrow_date'],
            ':due_date'    => $_POST['due_date'],
            ':status'      => 'borrowed'
        ]);
        $borrow_id = $stmtT->fetchColumn();

        // Support one or more equipment items attached to the same transaction
        $equip_ids = (array)($_POST['equipment_id'] ?? []);
        $qtys      = (array)($_POST['quantity'] ?? []);
        $stmtI = $pdo->prepare('INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES (:borrow_id, :equipment_id, :quantity)');
        foreach ($equip_ids as $idx => $eid) {
            if ($eid === '' || $eid === null) {
                continue;
            }
            $qty = (int)($qtys[$idx] ?? 1);
            if ($qty < 1) {
                $qty = 1;
            }
            $stmtI->execute([
                ':borrow_id'    => $borrow_id,
                ':equipment_id' => (int)$eid,
                ':quantity'     => $qty
            ]);
        }

        $pdo->commit();
        setFlash($merged ? "New borrowing added for existing borrower!" : "Borrower and transaction added successfully!");
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash("Error creating record: " . friendlyError($e), 'error');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// REGISTER BORROWER ONLY (no checkout) — makes the "Borrowers Without Transactions" LEFT JOIN report demonstrable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_borrower_only') {
    try {
        $stmt = $pdo->prepare('INSERT INTO borrowers (borrower_no, full_name, borrower_type) VALUES (:borrower_no, :full_name, :borrower_type)');
        $stmt->execute([
            ':borrower_no'   => trim($_POST['borrower_no'] ?? ''),
            ':full_name'     => trim($_POST['full_name'] ?? ''),
            ':borrower_type' => strtolower(trim($_POST['borrower_type'] ?? '')),
        ]);
        setFlash('Borrower registered successfully (no borrowing yet).');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        setFlash('Error registering borrower: ' . friendlyError($e), 'error');
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
            setFlash("Error adding equipment: " . friendlyError($e), 'error');
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
            setFlash("Error updating equipment: " . friendlyError($e), 'error');
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
            setFlash("Error deleting equipment: " . friendlyError($e), 'error');
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
            ':borrower_type' => strtolower(trim($_POST['borrower_type'] ?? '')),
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

            // 3. Update the first borrowed item of this transaction (safe for multi-item transactions)
            $item_eid = $_POST['equipment_id'] ?? null;
            if (is_array($item_eid)) {
                $item_eid = reset($item_eid);
            }
            if (!empty($item_eid)) {
                $item_qty = $_POST['quantity'] ?? 1;
                if (is_array($item_qty)) {
                    $item_qty = reset($item_qty);
                }
                $stmtI = $pdo->prepare('UPDATE borrow_items SET equipment_id = :equipment_id, quantity = :quantity WHERE borrow_item_id = (SELECT borrow_item_id FROM borrow_items WHERE borrow_id = :borrow_id ORDER BY borrow_item_id LIMIT 1)');
                $stmtI->execute([
                    ':equipment_id' => (int)$item_eid,
                    ':quantity'     => (int)$item_qty,
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
        setFlash("Error updating record: " . friendlyError($e), 'error');
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
        setFlash("Error deleting borrower: " . friendlyError($e), 'error');
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
    // 1. SQL-language function: fn_total_borrowed
    $pdo->exec("
        CREATE OR REPLACE FUNCTION fn_total_borrowed(p_equipment_id INT)
        RETURNS INT AS $$
            SELECT COALESCE(SUM(quantity), 0)::INT FROM borrow_items WHERE equipment_id = p_equipment_id;
        $$ LANGUAGE sql;
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
            v_already_held  INT := 0;
        BEGIN
            SELECT quantity_available INTO v_available_qty FROM equipment WHERE equipment_id = NEW.equipment_id;
            -- When editing, the quantity this same item already holds counts back as available
            IF (TG_OP = 'UPDATE' AND OLD.equipment_id = NEW.equipment_id) THEN
                v_already_held := OLD.quantity;
            END IF;
            IF NEW.quantity > v_available_qty + v_already_held THEN
                RAISE EXCEPTION 'Requested quantity (%) exceeds available stock (%)', NEW.quantity, v_available_qty + v_already_held;
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;

        DROP TRIGGER IF EXISTS trg_validate_borrow_quantity ON borrow_items;
        CREATE TRIGGER trg_validate_borrow_quantity
        BEFORE INSERT OR UPDATE ON borrow_items
        FOR EACH ROW EXECUTE FUNCTION fn_validate_borrow_quantity();
    ");

    // 4. Audit Table & AFTER Audit Trigger (captures OLD and NEW quantities)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS borrow_items_audit (
            audit_id SERIAL PRIMARY KEY,
            borrow_id INT,
            equipment_id INT,
            quantity INT,
            old_quantity INT,
            new_quantity INT,
            action VARCHAR(10),
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        ALTER TABLE borrow_items_audit ADD COLUMN IF NOT EXISTS old_quantity INT;
        ALTER TABLE borrow_items_audit ADD COLUMN IF NOT EXISTS new_quantity INT;

        CREATE OR REPLACE FUNCTION fn_borrow_items_audit()
        RETURNS TRIGGER AS $$
        BEGIN
            IF (TG_OP = 'INSERT') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, old_quantity, new_quantity, action)
                VALUES (NEW.borrow_id, NEW.equipment_id, NEW.quantity, NULL, NEW.quantity, 'INSERT');
            ELSIF (TG_OP = 'UPDATE') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, old_quantity, new_quantity, action)
                VALUES (NEW.borrow_id, NEW.equipment_id, NEW.quantity, OLD.quantity, NEW.quantity, 'UPDATE');
            ELSIF (TG_OP = 'DELETE') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, old_quantity, new_quantity, action)
                VALUES (OLD.borrow_id, OLD.equipment_id, OLD.quantity, OLD.quantity, NULL, 'DELETE');
            END IF;
            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;

        DROP TRIGGER IF EXISTS trg_borrow_items_audit ON borrow_items;
        CREATE TRIGGER trg_borrow_items_audit
        AFTER INSERT OR UPDATE OR DELETE ON borrow_items
        FOR EACH ROW EXECUTE FUNCTION fn_borrow_items_audit();
    ");

    // 5. AFTER Stock Sync Trigger: keep equipment.quantity_available in step with borrow_items
    $pdo->exec("
        CREATE OR REPLACE FUNCTION fn_sync_equipment_stock()
        RETURNS TRIGGER AS $$
        BEGIN
            IF (TG_OP = 'INSERT') THEN
                UPDATE equipment SET quantity_available = quantity_available - NEW.quantity WHERE equipment_id = NEW.equipment_id;
            ELSIF (TG_OP = 'DELETE') THEN
                UPDATE equipment SET quantity_available = quantity_available + OLD.quantity WHERE equipment_id = OLD.equipment_id;
            ELSIF (TG_OP = 'UPDATE') THEN
                UPDATE equipment SET quantity_available = quantity_available + OLD.quantity WHERE equipment_id = OLD.equipment_id;
                UPDATE equipment SET quantity_available = quantity_available - NEW.quantity WHERE equipment_id = NEW.equipment_id;
            END IF;
            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;

        DROP TRIGGER IF EXISTS trg_sync_equipment_stock ON borrow_items;
        CREATE TRIGGER trg_sync_equipment_stock
        AFTER INSERT OR UPDATE OR DELETE ON borrow_items
        FOR EACH ROW EXECUTE FUNCTION fn_sync_equipment_stock();
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

// Standardized display labels using CONCAT_WS(), TRIM(), LOWER() and INITCAP()
$name_labels = $pdo->query("
    SELECT CONCAT_WS(' | ', TRIM(borrower_no), INITCAP(TRIM(full_name)), LOWER(borrower_type)) AS label
    FROM borrowers
    ORDER BY borrower_no
")->fetchAll(PDO::FETCH_COLUMN);

// Equipment Without Borrowing History Report (Rubric Criteria)
$unborrowed_equipment = $pdo->query("SELECT e.property_no, e.equipment_name FROM equipment e LEFT JOIN borrow_items bi ON e.equipment_id = bi.equipment_id WHERE bi.borrow_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);

// Monthly Borrowing Report using EXTRACT() and DATE_TRUNC() date functions
$monthly_report = $pdo->query("
    SELECT
        EXTRACT(YEAR FROM DATE_TRUNC('month', bt.borrow_date))::int AS yr,
        TO_CHAR(DATE_TRUNC('month', bt.borrow_date), 'Mon YYYY') AS month_label,
        COUNT(*) AS total_borrows
    FROM borrow_transactions bt
    GROUP BY DATE_TRUNC('month', bt.borrow_date)
    ORDER BY DATE_TRUNC('month', bt.borrow_date) DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Reuse the created views inside the application (Rubric Criteria: Views + multiple INNER JOINs)
$vw_details = $pdo->query("SELECT borrower_no, full_name, equipment_name, quantity, borrow_date, due_date, status FROM vw_borrowing_details ORDER BY borrow_date DESC, full_name")->fetchAll(PDO::FETCH_ASSOC);
$vw_usage   = $pdo->query("SELECT property_no, equipment_name, total_transactions, total_quantity_borrowed, availability_status FROM vw_equipment_usage ORDER BY equipment_name")->fetchAll(PDO::FETCH_ASSOC);

// Transaction Status Summary (GROUP BY on status)
$status_summary = $pdo->query("SELECT status, COUNT(*) AS total FROM borrow_transactions GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_ASSOC);

// Borrowers Without Transactions (LEFT JOIN + INITCAP/UPPER string functions)
$borrowers_no_txn = $pdo->query("
    SELECT b.borrower_no, INITCAP(b.full_name) AS full_name, UPPER(b.borrower_type) AS type_upper
    FROM borrowers b
    LEFT JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    WHERE bt.borrow_id IS NULL
    ORDER BY b.borrower_no
")->fetchAll(PDO::FETCH_ASSOC);

// Grouped Borrowing Analysis: GROUP BY + HAVING (borrower types with 2 or more transactions)
$grouped_analysis = $pdo->query("
    SELECT b.borrower_type,
           COUNT(DISTINCT bt.borrow_id) AS transaction_count,
           COALESCE(SUM(bi.quantity), 0) AS total_items
    FROM borrowers b
    JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    LEFT JOIN borrow_items bi ON bi.borrow_id = bt.borrow_id
    GROUP BY b.borrower_type
    HAVING COUNT(DISTINCT bt.borrow_id) >= 2
    ORDER BY transaction_count DESC, b.borrower_type
")->fetchAll(PDO::FETCH_ASSOC);

// Aggregate functions per equipment: COUNT / SUM / AVG / MIN / MAX (+ ROUND)
$equip_aggregates = $pdo->query("
    SELECT e.property_no, e.equipment_name,
           COUNT(bi.borrow_item_id) AS times_borrowed,
           COALESCE(SUM(bi.quantity), 0) AS total_qty,
           COALESCE(ROUND(AVG(bi.quantity)::numeric, 2), 0) AS avg_qty,
           COALESCE(MIN(bi.quantity), 0) AS min_qty,
           COALESCE(MAX(bi.quantity), 0) AS max_qty
    FROM equipment e
    LEFT JOIN borrow_items bi ON e.equipment_id = bi.equipment_id
    GROUP BY e.equipment_id, e.property_no, e.equipment_name
    ORDER BY times_borrowed DESC, e.equipment_name
")->fetchAll(PDO::FETCH_ASSOC);

// Combined Borrower Activity: UNION ALL (keeps duplicates) across two compatible result sets
$activity_union_all = $pdo->query("
    SELECT INITCAP(b.full_name) AS borrower, 'Active' AS activity
    FROM borrowers b JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    WHERE bt.status = 'borrowed'
    UNION ALL
    SELECT INITCAP(b.full_name) AS borrower, 'Overdue' AS activity
    FROM borrowers b JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    WHERE bt.status = 'borrowed' AND bt.due_date < CURRENT_DATE
    ORDER BY activity, borrower
")->fetchAll(PDO::FETCH_ASSOC);

// Same two result sets combined with UNION (duplicates removed) to contrast with UNION ALL
$activity_union = $pdo->query("
    SELECT INITCAP(b.full_name) AS borrower
    FROM borrowers b JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    WHERE bt.status = 'borrowed'
    UNION
    SELECT INITCAP(b.full_name)
    FROM borrowers b JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    WHERE bt.status = 'borrowed' AND bt.due_date < CURRENT_DATE
")->fetchAll(PDO::FETCH_COLUMN);

// Demonstrate the Week 10 functions with different inputs (one row per equipment item)
$fn_demo = $pdo->query("
    SELECT property_no, equipment_name, quantity_available,
           fn_check_equipment_availability(equipment_id, 1) AS can_lend_one,
           fn_total_borrowed(equipment_id) AS total_borrowed_all_time
    FROM equipment
    ORDER BY equipment_name
")->fetchAll(PDO::FETCH_ASSOC);

// Recent audit trail written automatically by the AFTER trigger
$audit_log = $pdo->query("
    SELECT audit_id, borrow_id, equipment_id, old_quantity, new_quantity, action, changed_at
    FROM borrow_items_audit
    ORDER BY audit_id DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// --- Live, NON-DESTRUCTIVE self-tests: every scenario runs inside a transaction that is rolled back ---
$self_tests = [];
$ref_borrower = $pdo->query("SELECT borrower_id FROM borrowers ORDER BY borrower_id LIMIT 1")->fetchColumn();
$ref_equip    = $pdo->query("SELECT equipment_id FROM equipment WHERE quantity_available >= 1 ORDER BY equipment_id LIMIT 1")->fetchColumn();
$ref_property = $pdo->query("SELECT property_no FROM equipment ORDER BY equipment_id LIMIT 1")->fetchColumn();

if ($ref_borrower && $ref_equip) {
    $pdo->beginTransaction();
    try {
        $test_borrow_id = $pdo->query("INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date, status)
                                       VALUES ($ref_borrower, CURRENT_DATE, CURRENT_DATE + 2, 'borrowed')
                                       RETURNING borrow_id")->fetchColumn();

        $sp_counter = 0;
        $runTest = function ($label, $want, callable $fn) use ($pdo, &$self_tests, &$sp_counter) {
            $sp_counter++;
            $sp = 'selftest_' . $sp_counter;
            $pdo->exec("SAVEPOINT $sp");
            try {
                $detail = $fn();
                $observed = 'Accepted' . ($detail ? ' — ' . $detail : '');
                $ok = ($want === 'accept');
            } catch (Exception $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT $sp");
                $observed = 'Rejected — ' . friendlyError($e);
                $ok = ($want === 'reject');
            }
            $self_tests[] = [
                'test'     => $label,
                'expected' => $want === 'accept' ? 'Accepted' : 'Rejected',
                'observed' => $observed,
                'result'   => $ok ? 'PASS' : 'FAIL',
            ];
        };

        $runTest('Insert a valid borrowing item (BEFORE trigger + audit)', 'accept', function () use ($pdo, $test_borrow_id, $ref_equip) {
            $pdo->exec("INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES ($test_borrow_id, $ref_equip, 1)");
            $logged = $pdo->query("SELECT COUNT(*) FROM borrow_items_audit WHERE borrow_id = $test_borrow_id AND action = 'INSERT'")->fetchColumn();
            return $logged ? 'audit INSERT logged by trigger' : 'audit row NOT logged';
        });

        $runTest('Borrow more than the available stock', 'reject', function () use ($pdo, $test_borrow_id, $ref_equip) {
            $pdo->exec("INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES ($test_borrow_id, $ref_equip, 999999)");
        });

        $runTest('Same equipment twice in one transaction (unique)', 'reject', function () use ($pdo, $test_borrow_id, $ref_equip) {
            $pdo->exec("INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES ($test_borrow_id, $ref_equip, 1)");
        });

        $runTest('Borrow item referencing unknown equipment (foreign key)', 'reject', function () use ($pdo, $test_borrow_id) {
            $pdo->exec("INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES ($test_borrow_id, 999999, 1)");
        });

        $runTest('Set available stock below zero (check)', 'reject', function () use ($pdo, $ref_equip) {
            $pdo->exec("UPDATE equipment SET quantity_available = -1 WHERE equipment_id = $ref_equip");
        });

        if ($ref_property !== null && $ref_property !== '') {
            $safe_prop = $pdo->quote($ref_property);
            $runTest('Add equipment with a duplicate property number', 'reject', function () use ($pdo, $safe_prop) {
                $pdo->exec("INSERT INTO equipment (property_no, equipment_name, quantity_available) VALUES ($safe_prop, 'Duplicate Test', 1)");
            });
        }

        $runTest('Update an item quantity (audit records old + new)', 'accept', function () use ($pdo, $test_borrow_id) {
            $pdo->exec("UPDATE borrow_items SET quantity = 2 WHERE borrow_id = $test_borrow_id");
            $row = $pdo->query("SELECT old_quantity, new_quantity FROM borrow_items_audit WHERE borrow_id = $test_borrow_id AND action = 'UPDATE' ORDER BY audit_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return ($row && $row['old_quantity'] !== null && $row['new_quantity'] !== null)
                ? "audit UPDATE logged (old={$row['old_quantity']}, new={$row['new_quantity']})"
                : 'audit UPDATE row NOT logged with old/new';
        });

        $runTest('Delete an item (audit records the old values)', 'accept', function () use ($pdo, $test_borrow_id) {
            $pdo->exec("DELETE FROM borrow_items WHERE borrow_id = $test_borrow_id");
            $row = $pdo->query("SELECT old_quantity FROM borrow_items_audit WHERE borrow_id = $test_borrow_id AND action = 'DELETE' ORDER BY audit_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return ($row && $row['old_quantity'] !== null)
                ? 'audit DELETE logged (old=' . $row['old_quantity'] . ')'
                : 'audit DELETE row NOT logged';
        });
    } catch (Exception $e) {
        // Safe to ignore — the rollback below discards any partial test data
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
$self_test_pass = 0;
foreach ($self_tests as $t) {
    if ($t['result'] === 'PASS') {
        $self_test_pass++;
    }
}

// --- 1. DATA RETRIEVAL, FILTERING, & SORTING ---
$search_name   = $_GET['search_name'] ?? '';
$filter_type   = $_GET['filter_type'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to'] ?? '';
$sort_by       = $_GET['sort_by'] ?? 'b.borrower_id';
$sort_dir      = $_GET['sort_dir'] ?? 'DESC';

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
if ($filter_status) {
    // Demonstrate IN: "overdue" also matches items still borrowed past their due date
    if ($filter_status === 'overdue') {
        $query .= " AND bt.status IN ('borrowed', 'overdue') AND bt.due_date < CURRENT_DATE";
    } else {
        $query .= " AND bt.status = :status";
        $params[':status'] = $filter_status;
    }
}
if ($date_from) {
    $query .= " AND bt.borrow_date >= :date_from";
    $params[':date_from'] = $date_from;
}
if ($date_to) {
    $query .= " AND bt.borrow_date <= :date_to";
    $params[':date_to'] = $date_to;
}

$query .= " ORDER BY $sort_by $sort_dir, b.full_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. AT-A-GLANCE SUMMARY (KPIs + de-duplicated per-type breakdown) ---
$kpi = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM borrowers) AS total_borrowers,
        (SELECT COUNT(*) FROM borrow_transactions WHERE status = 'borrowed') AS active_borrowings,
        (SELECT COALESCE(SUM(bi.quantity), 0)
           FROM borrow_items bi
           JOIN borrow_transactions bt ON bt.borrow_id = bi.borrow_id
          WHERE bt.status = 'borrowed') AS items_out,
        (SELECT COUNT(*) FROM borrow_transactions
          WHERE status = 'borrowed' AND due_date < CURRENT_DATE) AS overdue
")->fetch(PDO::FETCH_ASSOC);

$summary_records = $pdo->query("
    SELECT
        b.borrower_type,
        COUNT(DISTINCT b.borrower_id) AS borrowers,
        COUNT(DISTINCT CASE WHEN bt.status = 'borrowed' THEN bt.borrow_id END) AS borrowings,
        COALESCE(SUM(CASE WHEN bt.status = 'borrowed' THEN bi.quantity ELSE 0 END), 0) AS items,
        COUNT(DISTINCT CASE WHEN bt.status = 'borrowed' AND bt.due_date < CURRENT_DATE THEN bt.borrow_id END) AS overdue
    FROM borrowers b
    LEFT JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    LEFT JOIN borrow_items bi ON bt.borrow_id = bi.borrow_id
    GROUP BY b.borrower_type
    ORDER BY b.borrower_type
")->fetchAll(PDO::FETCH_ASSOC);
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
         .kpi-grid { display: flex; gap: 20px; margin-bottom: 20px; }
         .kpi { flex: 1; text-align: center; padding: 15px; border-radius: 8px; background: #f8f9fa; border: 1px solid #eee; }
         .kpi-value { display: block; font-size: 1.8em; font-weight: 700; color: #007bff; }
         .kpi-label { display: block; color: #666; font-size: 0.9em; margin-top: 4px; }
         .kpi-alert .kpi-value { color: #dc3545; }
         tr.total-row td { font-weight: 700; background: #f8f9fa; }

         /* Alert Styles */
         .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
         .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
         .alert-error { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
         
         /* Quick Action Styles */
         .btn-edit { background-color: #ffc107; color: #212529; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
         .btn-edit:hover { background-color: #e0a800; }
         .action-group { display: flex; gap: 5px; }

         /* Navigation */
         .nav { position: sticky; top: 0; z-index: 20; display: flex; flex-wrap: wrap; gap: 8px; background: #007bff; padding: 10px 12px; border-radius: 8px; margin-bottom: 20px; }
         .nav a { color: #fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; font-size: 0.92em; }
         .nav a:hover { background: rgba(255,255,255,0.25); }

         /* Reports */
         .report-note { color: #666; margin: 0 0 10px; font-size: 0.9em; }
         .report-note code { background: #f1f3f5; padding: 1px 5px; border-radius: 3px; }
         .card h3 { margin-bottom: 6px; }
     </style>
 </head>
 <body>
     <nav class="nav">
         <a href="#summary">Summary</a>
         <a href="#filter">Search Records</a>
         <a href="#create">Add Record</a>
         <a href="#records">Records</a>
         <a href="#equipment">Equipment</a>
         <a href="#reports">Reports</a>
     </nav>
     <h1>Borrower & Transaction Management</h1>

     <?php 
     if (isset($_SESSION['flash'])) {
         $flash = $_SESSION['flash'];
         echo "<div class='alert alert-{$flash['type']}'>{$flash['msg']}</div>";
         unset($_SESSION['flash']);
     }
     ?>

    <!-- Summary Section: at-a-glance KPIs + per-type breakdown -->
    <div class="card" id="summary">
        <h2>System Summary</h2>
        <div class="kpi-grid">
            <div class="kpi">
                <span class="kpi-value"><?= (int)$kpi['total_borrowers'] ?></span>
                <span class="kpi-label">Total Borrowers</span>
            </div>
            <div class="kpi">
                <span class="kpi-value"><?= (int)$kpi['active_borrowings'] ?></span>
                <span class="kpi-label">Active Borrowings</span>
            </div>
            <div class="kpi">
                <span class="kpi-value"><?= (int)$kpi['items_out'] ?></span>
                <span class="kpi-label">Items Currently Out</span>
            </div>
            <div class="kpi kpi-alert">
                <span class="kpi-value"><?= (int)$kpi['overdue'] ?></span>
                <span class="kpi-label">Overdue</span>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Borrower Type</th>
                    <th>Borrowers</th>
                    <th>Active Borrowings</th>
                    <th>Items Out</th>
                    <th>Overdue</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $tot = ['borrowers' => 0, 'borrowings' => 0, 'items' => 0, 'overdue' => 0];
                foreach ($summary_records as $sum):
                    $tot['borrowers']  += $sum['borrowers'];
                    $tot['borrowings'] += $sum['borrowings'];
                    $tot['items']      += $sum['items'];
                    $tot['overdue']    += $sum['overdue'];
                ?>
                <tr>
                    <td><?= htmlspecialchars(ucfirst($sum['borrower_type'])) ?></td>
                    <td><?= (int)$sum['borrowers'] ?></td>
                    <td><?= (int)$sum['borrowings'] ?></td>
                    <td><?= (int)$sum['items'] ?></td>
                    <td><?= (int)$sum['overdue'] ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td>Overall</td>
                    <td><?= $tot['borrowers'] ?></td>
                    <td><?= $tot['borrowings'] ?></td>
                    <td><?= $tot['items'] ?></td>
                    <td><?= $tot['overdue'] ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Filter & Sort Section -->
    <div class="card" id="filter">
        <h2>Search & Filter</h2>
        <form method="get">
            <input type="text" name="search_name" placeholder="Search by name..." value="<?= htmlspecialchars($search_name) ?>">
            <select name="filter_type">
                <option value="">-- All Types --</option>
                <option value="student" <?= $filter_type === 'student' ? 'selected' : '' ?>>Student</option>
                <option value="faculty" <?= $filter_type === 'faculty' ? 'selected' : '' ?>>Faculty</option>
            </select>
            <select name="filter_status">
                <option value="">-- All Statuses --</option>
                <option value="borrowed" <?= $filter_status === 'borrowed' ? 'selected' : '' ?>>Borrowed</option>
                <option value="returned" <?= $filter_status === 'returned' ? 'selected' : '' ?>>Returned</option>
                <option value="overdue" <?= $filter_status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
            </select>
            <label style="color:#666;font-size:0.9em;">From <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></label>
            <label style="color:#666;font-size:0.9em;">To <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></label>
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

    <div class="card" id="create">
        <h2>Create Borrower & Checkout</h2>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                <input type="text" name="borrower_no" placeholder="Borrower No." required>
                <input type="text" name="full_name" placeholder="Full Name" required>
                <select name="borrower_type" required>
                    <option value="">-- Select Type --</option>
                    <option value="student">Student</option>
                    <option value="faculty">Faculty</option>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                <input type="date" name="borrow_date" value="<?= date('Y-m-d') ?>" required>
                <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" required>
            </div>
            <p class="report-note" style="margin-top:12px;">Equipment items (add one row per item borrowed on this transaction):</p>
            <div id="items-container">
                <div class="item-row" style="display: grid; grid-template-columns: 2fr 1fr auto; gap: 10px; margin-bottom: 6px;">
                    <select name="equipment_id[]" required>
                        <option value="">-- Equipment --</option>
                        <?php foreach ($equipment_list as $item): ?>
                            <option value="<?= $item['equipment_id'] ?>"><?= htmlspecialchars($item['equipment_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="quantity[]" placeholder="Qty" value="1" min="1" required>
                    <button type="button" class="delete" onclick="removeItemRow(this)">Remove</button>
                </div>
            </div>
            <button type="button" onclick="addItemRow()" style="background:#6c757d;margin-top:4px;">+ Add Item</button>
            <button type="submit" style="margin-top: 10px; width: 100%;">Add Record</button>
        </form>

        <form method="post" style="margin-top:16px; border-top:1px solid #eee; padding-top:12px;">
            <p class="report-note">Or register a borrower <strong>without</strong> a checkout yet (this person then appears in the "Borrowers Without Transactions" report):</p>
            <input type="hidden" name="action" value="create_borrower_only">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 10px;">
                <input type="text" name="borrower_no" placeholder="Borrower No." required>
                <input type="text" name="full_name" placeholder="Full Name" required>
                <select name="borrower_type" required>
                    <option value="">-- Type --</option>
                    <option value="student">Student</option>
                    <option value="faculty">Faculty</option>
                </select>
                <button type="submit" style="background:#28a745;">Register</button>
            </div>
        </form>
    </div>

    <div class="card" id="records">
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
                    <td><?= htmlspecialchars(ucfirst($row['borrower_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
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
                    <option value="student">Student</option>
                    <option value="faculty">Faculty</option>
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

    <hr id="equipment">
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

    <!-- ================= REPORTS (Week 6-8 SQL) ================= -->
    <hr id="reports">
    <h1>Reports</h1>

    <!-- Complete Borrowing Report: multiple INNER JOINs via view vw_borrowing_details -->
    <div class="card">
        <h2>Complete Borrowing Report</h2>
        <p class="report-note">Source: view <code>vw_borrowing_details</code> &mdash; borrowers &rarr; transactions &rarr; items &rarr; equipment (multiple joins).</p>
        <?php if ($vw_details): ?>
        <table>
            <thead>
                <tr><th>Borrower No.</th><th>Name</th><th>Equipment</th><th>Qty</th><th>Borrowed</th><th>Due</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($vw_details as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['borrower_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['quantity'] ?? 0) ?></td>
                    <td><?= htmlspecialchars($r['borrow_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['due_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(ucfirst($r['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">No borrowing records yet.</p>
        <?php endif; ?>
    </div>

    <!-- Equipment Borrowing Summary: view vw_equipment_usage (LEFT JOIN + COUNT/SUM + CASE) -->
    <div class="card">
        <h2>Equipment Borrowing Summary</h2>
        <p class="report-note">Source: view <code>vw_equipment_usage</code> &mdash; LEFT JOIN with COUNT()/SUM() aggregation and a CASE availability classification.</p>
        <table>
            <thead>
                <tr><th>Property No.</th><th>Equipment</th><th>Transactions</th><th>Total Qty Borrowed</th><th>Availability</th></tr>
            </thead>
            <tbody>
                <?php foreach ($vw_usage as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['property_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['total_transactions'] ?? 0) ?></td>
                    <td><?= (int)($r['total_quantity_borrowed'] ?? 0) ?></td>
                    <td><?= htmlspecialchars($r['availability_status'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Equipment Without Borrowing History: LEFT JOIN antijoin -->
    <div class="card">
        <h2>Equipment Without Borrowing History</h2>
        <p class="report-note">Source: <code>LEFT JOIN</code> that keeps equipment rows having no matching borrow item.</p>
        <?php if ($unborrowed_equipment): ?>
        <table>
            <thead><tr><th>Property No.</th><th>Equipment</th></tr></thead>
            <tbody>
                <?php foreach ($unborrowed_equipment as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['property_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">Every equipment item has been borrowed at least once.</p>
        <?php endif; ?>
    </div>

    <!-- Monthly Borrowing Report: EXTRACT + DATE_TRUNC + TO_CHAR + GROUP BY -->
    <div class="card">
        <h2>Monthly Borrowing Report</h2>
        <p class="report-note">Source: <code>DATE_TRUNC()</code>/<code>EXTRACT()</code> grouped by month with <code>TO_CHAR()</code> formatting.</p>
        <table>
            <thead><tr><th>Year</th><th>Month</th><th>Total Borrowings</th></tr></thead>
            <tbody>
                <?php foreach ($monthly_report as $r): ?>
                <tr>
                    <td><?= (int)($r['yr'] ?? 0) ?></td>
                    <td><?= htmlspecialchars($r['month_label'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['total_borrows'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Transaction Status Summary: GROUP BY on status -->
    <div class="card">
        <h2>Transaction Status Summary</h2>
        <p class="report-note">Source: <code>GROUP BY</code> on transaction status.</p>
        <table>
            <thead><tr><th>Status</th><th>Transactions</th></tr></thead>
            <tbody>
                <?php foreach ($status_summary as $r): ?>
                <tr>
                    <td><?= htmlspecialchars(ucfirst($r['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['total'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Borrowers Without Transactions: LEFT JOIN + string functions -->
    <div class="card">
        <h2>Borrowers Without Transactions</h2>
        <p class="report-note">Source: <code>LEFT JOIN</code> with <code>INITCAP()</code>/<code>UPPER()</code> string functions.</p>
        <?php if ($borrowers_no_txn): ?>
        <table>
            <thead><tr><th>Borrower No.</th><th>Name</th><th>Type</th></tr></thead>
            <tbody>
                <?php foreach ($borrowers_no_txn as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['borrower_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['type_upper'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">Every registered borrower has at least one transaction.</p>
        <?php endif; ?>
    </div>

    <!-- Grouped Borrowing Analysis: GROUP BY + HAVING -->
    <div class="card">
        <h2>Grouped Borrowing Analysis</h2>
        <p class="report-note">Source: <code>GROUP BY borrower_type</code> + <code>HAVING COUNT(*) &gt;= 2</code> &mdash; borrower types with two or more transactions.</p>
        <?php if ($grouped_analysis): ?>
        <table>
            <thead><tr><th>Borrower Type</th><th>Transactions</th><th>Total Items Borrowed</th></tr></thead>
            <tbody>
                <?php foreach ($grouped_analysis as $r): ?>
                <tr>
                    <td><?= htmlspecialchars(ucfirst($r['borrower_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['transaction_count'] ?? 0) ?></td>
                    <td><?= (int)($r['total_items'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">No borrower type has two or more transactions yet.</p>
        <?php endif; ?>
    </div>

    <!-- Aggregate Functions per Equipment: COUNT/SUM/AVG/MIN/MAX + ROUND -->
    <div class="card">
        <h2>Equipment Aggregate Summary</h2>
        <p class="report-note">Source: <code>COUNT()</code>, <code>SUM()</code>, <code>AVG()</code>, <code>MIN()</code>, <code>MAX()</code> with <code>ROUND()</code>.</p>
        <table>
            <thead>
                <tr><th>Property No.</th><th>Equipment</th><th>Times Borrowed</th><th>Total Qty</th><th>Avg Qty</th><th>Min Qty</th><th>Max Qty</th></tr>
            </thead>
            <tbody>
                <?php foreach ($equip_aggregates as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['property_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['times_borrowed'] ?? 0) ?></td>
                    <td><?= (int)($r['total_qty'] ?? 0) ?></td>
                    <td><?= htmlspecialchars((string)($r['avg_qty'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['min_qty'] ?? 0) ?></td>
                    <td><?= (int)($r['max_qty'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Combined Borrower Activity: UNION ALL vs UNION -->
    <div class="card">
        <h2>Combined Borrower Activity</h2>
        <p class="report-note">Source: two compatible result sets combined with <code>UNION ALL</code> (keeps duplicates) vs <code>UNION</code> (removes them). Rows: UNION ALL = <?= count($activity_union_all) ?>, UNION = <?= count($activity_union) ?>.</p>
        <table>
            <thead><tr><th>Borrower</th><th>Activity</th></tr></thead>
            <tbody>
                <?php foreach ($activity_union_all as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['borrower'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['activity'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <h3>Distinct borrowers across both sets <span class="report-note">(<code>UNION</code>)</span></h3>
        <p class="report-note"><?= $activity_union ? htmlspecialchars(implode(', ', $activity_union), ENT_QUOTES, 'UTF-8') : 'None' ?></p>
    </div>

    <!-- Week 10 function demonstration -->
    <div class="card">
        <h2>Function Demonstration</h2>
        <p class="report-note">Source: <code>fn_total_borrowed()</code> (SQL function) and <code>fn_check_equipment_availability()</code> (PL/pgSQL), called once per equipment row.</p>
        <table>
            <thead><tr><th>Property No.</th><th>Equipment</th><th>Available</th><th>Can Lend 1?</th><th>Total Borrowed (all time)</th></tr></thead>
            <tbody>
                <?php foreach ($fn_demo as $r):
                    $can = ($r['can_lend_one'] === true || $r['can_lend_one'] === 't');
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['property_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['quantity_available'] ?? 0) ?></td>
                    <td><?= $can ? 'Yes' : 'No' ?></td>
                    <td><?= (int)($r['total_borrowed_all_time'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Recent audit trail from the AFTER trigger -->
    <div class="card">
        <h2>Audit Trail (recent)</h2>
        <p class="report-note">Written automatically by the AFTER trigger <code>trg_borrow_items_audit</code> using <code>NEW</code>, <code>OLD</code> and <code>TG_OP</code>.</p>
        <?php if ($audit_log): ?>
        <table>
            <thead><tr><th>#</th><th>Borrow ID</th><th>Equip. ID</th><th>Old Qty</th><th>New Qty</th><th>Action</th><th>When</th></tr></thead>
            <tbody>
                <?php foreach ($audit_log as $r): ?>
                <tr>
                    <td><?= (int)$r['audit_id'] ?></td>
                    <td><?= (int)$r['borrow_id'] ?></td>
                    <td><?= (int)$r['equipment_id'] ?></td>
                    <td><?= $r['old_quantity'] === null ? '-' : (int)$r['old_quantity'] ?></td>
                    <td><?= $r['new_quantity'] === null ? '-' : (int)$r['new_quantity'] ?></td>
                    <td><?= htmlspecialchars($r['action'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['changed_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">No audit entries yet.</p>
        <?php endif; ?>
    </div>

    <!-- Live validation / trigger / constraint self-tests (rolled back, non-destructive) -->
    <div class="card">
        <h2>Validation &amp; Trigger Self-Test</h2>
        <p class="report-note">
            Each scenario below is executed <strong>live against PostgreSQL</strong> inside a transaction that is
            <strong>rolled back</strong>, so no data is permanently changed. Passed
            <strong><?= $self_test_pass ?>/<?= count($self_tests) ?></strong>.
        </p>
        <?php if ($self_tests): ?>
        <table>
            <thead><tr><th>Test Scenario</th><th>Expected</th><th>Observed</th><th>Result</th></tr></thead>
            <tbody>
                <?php foreach ($self_tests as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['test'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($t['expected'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($t['observed'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge <?= $t['result'] === 'PASS' ? 'badge-ontime' : 'badge-overdue' ?>"><?= $t['result'] ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">Self-tests unavailable — no borrower or equipment records to reference.</p>
        <?php endif; ?>
    </div>

    <!-- Reference queries: DISTINCT + UNION -->
    <div class="card">
        <h2>Reference Lists</h2>
        <h3>Registered Borrower Types <span class="report-note">(<code>DISTINCT</code>)</span></h3>
        <p class="report-note">
            <?= $distinct_types ? htmlspecialchars(implode(', ', array_map('ucfirst', $distinct_types)), ENT_QUOTES, 'UTF-8') : 'None' ?>
        </p>
        <h3>Combined Name Directory <span class="report-note">(<code>UNION</code> of borrowers and equipment)</span></h3>
        <p class="report-note">
            <?= $all_unique_names ? htmlspecialchars(implode(', ', $all_unique_names), ENT_QUOTES, 'UTF-8') : 'None' ?>
        </p>
        <h3>Standardized Borrower Labels <span class="report-note">(<code>CONCAT_WS()</code>, <code>TRIM()</code>, <code>LOWER()</code>, <code>INITCAP()</code>)</span></h3>
        <p class="report-note">
            <?= $name_labels ? htmlspecialchars(implode('; ', $name_labels), ENT_QUOTES, 'UTF-8') : 'None' ?>
        </p>
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

        function addItemRow() {
            var container = document.getElementById('items-container');
            var rows = container.querySelectorAll('.item-row');
            var clone = rows[rows.length - 1].cloneNode(true);
            clone.querySelector('select[name="equipment_id[]"]').value = '';
            clone.querySelector('input[name="quantity[]"]').value = '1';
            container.appendChild(clone);
        }

        function removeItemRow(btn) {
            var container = document.getElementById('items-container');
            var rows = container.querySelectorAll('.item-row');
            if (rows.length <= 1) {
                return; // keep at least one item row
            }
            btn.closest('.item-row').remove();
        }
    </script>
</body>
</html>