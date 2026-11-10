<?php
session_start();

$host     = 'localhost';
$port     = '5432';
$dbname   = 'postgres';
$user     = 'postgres';
$password = 'woshidaxuesheng';

// Demonstration operator label recorded by the Week 10 audit trigger (see fn_borrow_items_audit).
$actor = 'postgre';

try {
    if ($password === '') {
        throw new RuntimeException('The DB_PASSWORD environment variable is not configured.');
    }
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname;connect_timeout=5", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection_info = $pdo->query("
        SELECT current_database() AS database_name,
               current_setting('server_version') AS server_version
    ")->fetch(PDO::FETCH_ASSOC);
    // Tag the session so the audit trigger can record who made each change.
    $pdo->prepare("SELECT set_config('wk5.actor', :actor, false)")->execute([':actor' => $actor]);
} catch (Throwable $e) {
    error_log('PostgreSQL connection failed: ' . $e->getMessage());
    http_response_code(503);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Database Connection Status | University Property Borrowing System</title>
        <link rel="stylesheet" href="wk5_database2.css">
    </head>
    <body class="connection-error">
        <main class="connection-status">
            <h1>University Property Borrowing System</h1>
            <h2>Database connection</h2>
            <p class="connection-error-status" role="status">Unable to connect to PostgreSQL. The borrowing system is temporarily unavailable.</p>
            <dl>
                <dt>Configured host</dt>
                <dd><?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Port</dt>
                <dd><?= htmlspecialchars($port, ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Database</dt>
                <dd><?= htmlspecialchars($dbname, ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
            <p>Check that PostgreSQL is running and that its connection settings and network access rules allow this XAMPP server to connect.</p>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$is_local_database = in_array(strtolower(trim($host)), ['localhost', '127.0.0.1', '::1'], true);

function redirectWithFlash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $message, 'type' => $type];
    header('Location: ' . ($_SERVER['PHP_SELF'] ?? '/'));
    exit;
}

try {
    $pdo->exec("
        DO \$migration\$
        DECLARE
            borrower_type_constraint RECORD;
            transaction_status_constraint RECORD;
        BEGIN
            IF NOT EXISTS (
                SELECT 1
                FROM pg_constraint
                WHERE conrelid = 'borrowers'::regclass
                  AND conname = 'borrowers_borrower_type_allowed_check'
                  AND position('student' IN pg_get_constraintdef(oid)) > 0
                  AND position('faculty' IN pg_get_constraintdef(oid)) > 0
                  AND position('staff' IN pg_get_constraintdef(oid)) > 0
            ) THEN
                FOR borrower_type_constraint IN
                    SELECT conname
                    FROM pg_constraint
                    WHERE conrelid = 'borrowers'::regclass
                      AND contype = 'c'
                      AND (
                          conname = 'borrowers_borrower_type_allowed_check'
                          OR (
                              pg_get_constraintdef(oid) ILIKE '%borrower_type%'
                              AND pg_get_constraintdef(oid) ILIKE '%student%'
                              AND pg_get_constraintdef(oid) ILIKE '%faculty%'
                              AND pg_get_constraintdef(oid) ILIKE '%staff%'
                          )
                      )
                LOOP
                    EXECUTE format('ALTER TABLE borrowers DROP CONSTRAINT %I', borrower_type_constraint.conname);
                END LOOP;

                UPDATE borrowers
                SET borrower_type = lower(btrim(borrower_type))
                WHERE borrower_type IS NOT NULL
                  AND lower(btrim(borrower_type)) IN ('student', 'faculty', 'staff')
                  AND borrower_type IS DISTINCT FROM lower(btrim(borrower_type));

                ALTER TABLE borrowers
                ADD CONSTRAINT borrowers_borrower_type_allowed_check
                CHECK (borrower_type IN ('student', 'faculty', 'staff'));
            ELSE
                FOR borrower_type_constraint IN
                    SELECT conname
                    FROM pg_constraint
                    WHERE conrelid = 'borrowers'::regclass
                      AND contype = 'c'
                      AND conname <> 'borrowers_borrower_type_allowed_check'
                      AND pg_get_constraintdef(oid) ILIKE '%borrower_type%'
                      AND pg_get_constraintdef(oid) ILIKE '%student%'
                      AND pg_get_constraintdef(oid) ILIKE '%faculty%'
                      AND pg_get_constraintdef(oid) ILIKE '%staff%'
                LOOP
                    EXECUTE format('ALTER TABLE borrowers DROP CONSTRAINT %I', borrower_type_constraint.conname);
                END LOOP;
            END IF;

            IF NOT EXISTS (
                SELECT 1
                FROM pg_constraint
                WHERE conrelid = 'borrow_transactions'::regclass
                  AND conname = 'borrow_transactions_status_allowed_check'
                  AND position('lower' IN pg_get_constraintdef(oid)) > 0
                  AND position('pending' IN pg_get_constraintdef(oid)) > 0
                  AND position('borrowed' IN pg_get_constraintdef(oid)) > 0
                  AND position('returned' IN pg_get_constraintdef(oid)) > 0
                  AND position('overdue' IN pg_get_constraintdef(oid)) > 0
            ) THEN
                FOR transaction_status_constraint IN
                    SELECT conname
                    FROM pg_constraint
                    WHERE conrelid = 'borrow_transactions'::regclass
                      AND contype = 'c'
                      AND (
                          conname = 'borrow_transactions_status_allowed_check'
                          OR (
                              pg_get_constraintdef(oid) ILIKE '%status%'
                              AND pg_get_constraintdef(oid) ILIKE '%borrowed%'
                              AND pg_get_constraintdef(oid) ILIKE '%returned%'
                              AND pg_get_constraintdef(oid) ILIKE '%overdue%'
                          )
                      )
                LOOP
                    EXECUTE format('ALTER TABLE borrow_transactions DROP CONSTRAINT %I', transaction_status_constraint.conname);
                END LOOP;

                ALTER TABLE borrow_transactions
                ADD CONSTRAINT borrow_transactions_status_allowed_check
                CHECK (lower(btrim(status)) IN ('pending', 'borrowed', 'returned', 'overdue'));
            ELSE
                FOR transaction_status_constraint IN
                    SELECT conname
                    FROM pg_constraint
                    WHERE conrelid = 'borrow_transactions'::regclass
                      AND contype = 'c'
                      AND conname <> 'borrow_transactions_status_allowed_check'
                      AND pg_get_constraintdef(oid) ILIKE '%status%'
                      AND pg_get_constraintdef(oid) ILIKE '%borrowed%'
                      AND pg_get_constraintdef(oid) ILIKE '%returned%'
                      AND pg_get_constraintdef(oid) ILIKE '%overdue%'
                LOOP
                    EXECUTE format('ALTER TABLE borrow_transactions DROP CONSTRAINT %I', transaction_status_constraint.conname);
                END LOOP;
            END IF;

            IF EXISTS (
                SELECT 1
                FROM pg_constraint
                WHERE conrelid = 'equipment'::regclass
                  AND conname = 'equipment_equipment_name_key'
                  AND contype = 'u'
            ) THEN
                ALTER TABLE equipment DROP CONSTRAINT equipment_equipment_name_key;
            END IF;

            CREATE UNIQUE INDEX IF NOT EXISTS equipment_equipment_name_lower_key
            ON equipment (lower(equipment_name));
        END
        \$migration\$;
    ");
} catch (Throwable $e) {
    error_log('Borrower, transaction, or equipment constraint migration failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Database schema setup failed. Check the application error log.');
}

function postText(string $key, string $label, int $maxLength): string {
    $value = $_POST[$key] ?? null;
    if (!is_string($value)) {
        throw new InvalidArgumentException("$label is required.");
    }
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException("$label is required.");
    }
    if (!mb_check_encoding($value, 'UTF-8')) {
        throw new InvalidArgumentException("$label contains invalid text.");
    }
    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        throw new InvalidArgumentException("$label must not exceed $maxLength characters.");
    }
    return $value;
}

function postPositiveInt(string $key, string $label): int {
    $rawValue = $_POST[$key] ?? null;
    if (!is_string($rawValue) && !is_int($rawValue)) {
        throw new InvalidArgumentException("$label must be a positive whole number.");
    }
    $value = filter_var($rawValue, FILTER_VALIDATE_INT);
    if ($value === false || $value === null || $value < 1) {
        throw new InvalidArgumentException("$label must be a positive whole number.");
    }
    return $value;
}

function postNonNegativeInt(string $key, string $label): int {
    $rawValue = $_POST[$key] ?? null;
    if (!is_string($rawValue) && !is_int($rawValue)) {
        throw new InvalidArgumentException("$label must be a whole number of zero or more.");
    }
    $value = filter_var($rawValue, FILTER_VALIDATE_INT);
    if ($value === false || $value === null || $value < 0) {
        throw new InvalidArgumentException("$label must be a whole number of zero or more.");
    }
    return $value;
}

function postDate(string $key, string $label): string {
    $value = $_POST[$key] ?? null;
    if (!validDate($value)) {
        throw new InvalidArgumentException("$label must be a valid date.");
    }
    return $value;
}

function validDate(mixed $value): bool {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function postBorrowerType(): string {
    $value = strtolower(postText('borrower_type', 'Borrower type', 20));
    if (!in_array($value, ['student', 'faculty', 'staff'], true)) {
        throw new InvalidArgumentException('Choose Student, Faculty, or Staff as the borrower type.');
    }
    return $value;
}

// Business-code masks keep identifiers consistent (e.g. B-0001). Stored uppercased.
function postBorrowerNo(): string {
    $value = strtoupper(postText('borrower_no', 'Borrower number', 20));
    if (!preg_match('/^B-[0-9]{4}$/D', $value)) {
        throw new InvalidArgumentException('Borrower number must follow the mask B-0000 (B, a hyphen, then four digits).');
    }
    return $value;
}

function postPropertyNo(): string {
    $value = strtoupper(postText('property_no', 'Property number', 20));
    if (!preg_match('/^EQ-[0-9]{4}$/D', $value)) {
        throw new InvalidArgumentException('Property number must follow the mask EQ-0000 (EQ, a hyphen, then four digits).');
    }
    return $value;
}

// Optional free-text field; returns null when blank so the column stays NULL.
function postOptionalText(string $key, string $label, int $maxLength): ?string {
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) {
        throw new InvalidArgumentException("$label is not valid text.");
    }
    $value = trim($raw);
    if ($value === '') {
        return null;
    }
    if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maxLength) {
        throw new InvalidArgumentException("$label must not exceed $maxLength characters.");
    }
    return $value;
}

function postOptionalEmail(): ?string {
    $value = postOptionalText('email', 'Email address', 120);
    if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address, or leave the field blank.');
    }
    return $value;
}

function postTransactionStatus(): string {
    $value = strtolower(postText('status', 'Transaction status', 20));
    if (!in_array($value, ['borrowed', 'returned', 'overdue'], true)) {
        throw new InvalidArgumentException('Choose a valid transaction status.');
    }
    return $value;
}

function postBorrowItems(string $equipmentKey = 'equipment_id', string $quantityKey = 'quantity'): array {
    $equipmentIds = $_POST[$equipmentKey] ?? null;
    $quantities = $_POST[$quantityKey] ?? null;
    if (!is_array($equipmentIds) || !is_array($quantities) || count($equipmentIds) < 1 || count($equipmentIds) !== count($quantities)) {
        throw new InvalidArgumentException('Add at least one equipment item and specify its quantity.');
    }

    $items = [];
    $seenEquipmentIds = [];
    if (count($equipmentIds) > 50) {
        throw new InvalidArgumentException('A transaction cannot contain more than 50 equipment items.');
    }
    foreach ($equipmentIds as $index => $rawEquipmentId) {
        if ((!is_string($rawEquipmentId) && !is_int($rawEquipmentId))
            || (!isset($quantities[$index]) || (!is_string($quantities[$index]) && !is_int($quantities[$index])))) {
            throw new InvalidArgumentException('Choose valid equipment and enter a quantity of at least one for every item.');
        }
        $equipmentId = filter_var($rawEquipmentId, FILTER_VALIDATE_INT);
        $quantity = filter_var($quantities[$index] ?? null, FILTER_VALIDATE_INT);
        if ($equipmentId === false || $equipmentId === null || $equipmentId < 1 || $quantity === false || $quantity === null || $quantity < 1) {
            throw new InvalidArgumentException('Choose valid equipment and enter a quantity of at least one for every item.');
        }
        if (isset($seenEquipmentIds[$equipmentId])) {
            throw new InvalidArgumentException('Each equipment type can only appear once per transaction. Combine duplicate rows into one quantity.');
        }
        $seenEquipmentIds[$equipmentId] = true;
        $items[] = ['equipment_id' => $equipmentId, 'quantity' => $quantity];
    }
    return $items;
}

$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$post_action = $request_method === 'POST' && is_string($_POST['action'] ?? null)
    ? $_POST['action']
    : '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($request_method === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? null;
    if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        redirectWithFlash('Your form session expired or was invalid. Please try again.', 'error');
    }
}

function friendlyError(Throwable $e): string {
    $map = [
        '23505' => 'Duplicate value — that identifier already exists. Please use a unique one.',
        '23503' => 'This action conflicts with a related record. Check your selection, and remove dependent records before deleting.',
        '23514' => 'Invalid value — it breaks an allowed range or list rule.',
        '23502' => 'A required field was left empty.',
        '22P02' => 'Invalid input format.',
        '22007' => 'Invalid date format.',
    ];
    $code = $e->getCode();
    if ($code === '23505' && $e instanceof PDOException
        && preg_match('/unique constraint "equipment_equipment_name_lower_key"/i', $e->getMessage())) {
        return 'An equipment item with this name already exists. Choose a different name.';
    }
    if ($code === '23514' && $e instanceof PDOException
        && preg_match('/violates check constraint "([^"]+)"/i', $e->getMessage(), $matches)) {
        return $map[$code] . ' Database rule: ' . $matches[1] . '.';
    }
    if (isset($map[$code])) {
        return $map[$code];
    }
    if ($e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }
    if ($e instanceof PDOException) {
        if ($code === 'P0001' && preg_match('/ERROR:\s*([^\r\n]+)/', $e->getMessage(), $m)) {
            return trim($m[1]);
        }
        error_log('Borrowing system database operation failed: ' . $e->getMessage());
        return 'The operation could not be completed. Please review the information and try again.';
    }
    if ($e instanceof RuntimeException) {
        return $e->getMessage();
    }
    error_log('Borrowing system operation failed: ' . $e->getMessage());
    return 'The operation could not be completed. Please review the information and try again.';
}

// CREATE TRANSACTION WITH ONE OR MORE BORROW ITEMS
if ($post_action === 'create_transaction') {
    try {
        $borrower_id = postPositiveInt('borrower_id', 'Borrower');
        $borrow_date = postDate('borrow_date', 'Borrow date');
        $due_date = postDate('due_date', 'Due date');
        $status = postTransactionStatus();
        $items = postBorrowItems();
        if ($due_date < $borrow_date) {
            throw new InvalidArgumentException('Due date cannot be earlier than the borrow date.');
        }

        $pdo->beginTransaction();
        $stmtT = $pdo->prepare('INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date, status) VALUES (:borrower_id, :borrow_date, :due_date, :status) RETURNING borrow_id');
        $stmtT->execute([
            ':borrower_id' => $borrower_id,
            ':borrow_date' => $borrow_date,
            ':due_date'    => $due_date,
            ':status'      => $status,
        ]);
        $borrow_id = $stmtT->fetchColumn();

        $stmtI = $pdo->prepare('INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES (:borrow_id, :equipment_id, :quantity)');
        foreach ($items as $item) {
            $stmtI->execute([
                ':borrow_id'    => $borrow_id,
                ':equipment_id' => $item['equipment_id'],
                ':quantity'     => $item['quantity'],
            ]);
        }

        $pdo->commit();
        redirectWithFlash('Borrowing transaction and its equipment items created successfully.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirectWithFlash('Error creating record: ' . friendlyError($e), 'error');
    }
}

// CREATE BORROWER
if ($post_action === 'create_borrower') {
    try {
        $borrower_no = postBorrowerNo();
        $full_name = postText('full_name', 'Full name', 100);
        $borrower_type = postBorrowerType();
        $email = postOptionalEmail();
        $stmt = $pdo->prepare('INSERT INTO borrowers (borrower_no, full_name, borrower_type, email) VALUES (:borrower_no, :full_name, :borrower_type, :email)');
        $stmt->execute([
            ':borrower_no'   => $borrower_no,
            ':full_name'     => $full_name,
            ':borrower_type' => $borrower_type,
            ':email'         => $email,
        ]);
        redirectWithFlash('Borrower registered successfully (no borrowing yet).');
    } catch (Exception $e) {
        redirectWithFlash('Error registering borrower: ' . friendlyError($e), 'error');
    }
}

// UPDATE TRANSACTION AND RECONCILE ITS BORROW ITEMS
if ($post_action === 'update_transaction') {
    try {
        $borrow_id = postPositiveInt('borrow_id', 'Transaction ID');
        $borrower_id = postPositiveInt('borrower_id', 'Borrower');
        $item_ids = $_POST['borrow_item_id'] ?? null;
        $items = postBorrowItems();
        $borrow_date = postDate('borrow_date', 'Borrow date');
        $due_date = postDate('due_date', 'Due date');
        $status = postTransactionStatus();
        if ($due_date < $borrow_date) {
            throw new InvalidArgumentException('Due date cannot be earlier than the borrow date.');
        }
        if (!is_array($item_ids) || count($item_ids) !== count($items)) {
            throw new InvalidArgumentException('The transaction items are invalid. Reload the page and try again.');
        }

        $pdo->beginTransaction();
        $stmtT = $pdo->prepare('UPDATE borrow_transactions SET borrower_id = :borrower_id, borrow_date = :borrow_date, due_date = :due_date, status = :status WHERE borrow_id = :borrow_id');
        $stmtT->execute([
            ':borrower_id' => $borrower_id,
            ':borrow_date' => $borrow_date,
            ':due_date' => $due_date,
            ':status' => $status,
            ':borrow_id' => $borrow_id,
        ]);
        if ($stmtT->rowCount() === 0) {
            $stmtExists = $pdo->prepare('SELECT 1 FROM borrow_transactions WHERE borrow_id = :borrow_id');
            $stmtExists->execute([':borrow_id' => $borrow_id]);
            if (!$stmtExists->fetchColumn()) {
                throw new RuntimeException('The selected borrowing transaction no longer exists.');
            }
        }

        $stmtExisting = $pdo->prepare('SELECT borrow_item_id FROM borrow_items WHERE borrow_id = :borrow_id');
        $stmtExisting->execute([':borrow_id' => $borrow_id]);
        $remaining_item_ids = array_fill_keys(array_map('strval', $stmtExisting->fetchAll(PDO::FETCH_COLUMN)), true);
        $stmtUpdateItem = $pdo->prepare('UPDATE borrow_items SET equipment_id = :equipment_id, quantity = :quantity WHERE borrow_item_id = :borrow_item_id AND borrow_id = :borrow_id');
        $stmtInsertItem = $pdo->prepare('INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES (:borrow_id, :equipment_id, :quantity)');

        foreach ($items as $idx => $item) {
            $raw_item_id = $item_ids[$idx] ?? null;
            if (!is_string($raw_item_id) && !is_int($raw_item_id)) {
                throw new InvalidArgumentException('The transaction items are invalid. Reload the page and try again.');
            }
            $raw_item_id = trim((string)$raw_item_id);
            $item_id = $raw_item_id === '' ? '' : filter_var($raw_item_id, FILTER_VALIDATE_INT);
            if ($item_id === false || ($item_id !== '' && $item_id < 1)) {
                throw new InvalidArgumentException('The transaction items are invalid. Reload the page and try again.');
            }
            $item_id = (string)$item_id;
            if ($item_id === '') {
                $stmtInsertItem->execute([
                    ':borrow_id' => $borrow_id,
                    ':equipment_id' => $item['equipment_id'],
                    ':quantity' => $item['quantity'],
                ]);
                continue;
            }
            if (!isset($remaining_item_ids[$item_id])) {
                throw new InvalidArgumentException('An item in this transaction was changed or removed. Reload the page and try again.');
            }
            $stmtUpdateItem->execute([
                ':equipment_id' => $item['equipment_id'],
                ':quantity' => $item['quantity'],
                ':borrow_item_id' => (int)$item_id,
                ':borrow_id' => $borrow_id,
            ]);
            unset($remaining_item_ids[$item_id]);
        }

        if ($remaining_item_ids) {
            $stmtDeleteItem = $pdo->prepare('DELETE FROM borrow_items WHERE borrow_item_id = :borrow_item_id AND borrow_id = :borrow_id');
            foreach (array_keys($remaining_item_ids) as $item_id) {
                $stmtDeleteItem->execute([
                    ':borrow_item_id' => (int)$item_id,
                    ':borrow_id' => $borrow_id,
                ]);
            }
        }

        $pdo->commit();
        redirectWithFlash('Borrowing transaction and its equipment items updated successfully.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirectWithFlash('Error updating transaction: ' . friendlyError($e), 'error');
    }
}

// DELETE A TRANSACTION AND ITS ITEMS
if ($post_action === 'delete_transaction') {
    try {
        $borrow_id = postPositiveInt('borrow_id', 'Transaction ID');
        $pdo->beginTransaction();
        $stmtItems = $pdo->prepare('DELETE FROM borrow_items WHERE borrow_id = :borrow_id');
        $stmtItems->execute([':borrow_id' => $borrow_id]);
        $stmtTransaction = $pdo->prepare('DELETE FROM borrow_transactions WHERE borrow_id = :borrow_id');
        $stmtTransaction->execute([':borrow_id' => $borrow_id]);
        if ($stmtTransaction->rowCount() === 0) {
            throw new RuntimeException('The selected borrowing transaction no longer exists.');
        }
        $pdo->commit();
        redirectWithFlash('Borrowing transaction and its equipment items deleted successfully.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirectWithFlash('Error deleting transaction: ' . friendlyError($e), 'error');
    }
}

// EQUIPMENT CRUD OPERATIONS
if ($request_method === 'POST') {
    $action = $post_action;
    
    // Create Equipment
    if ($action === 'create_equipment') {
        try {
            $property_no = postPropertyNo();
            $equipment_name = postText('equipment_name', 'Equipment name', 100);
            $category = postOptionalText('category', 'Category', 50);
            $quantity_available = postNonNegativeInt('quantity_available', 'Available quantity');
            $stmt = $pdo->prepare('INSERT INTO equipment (property_no, equipment_name, category, quantity_available) VALUES (:property_no, :equipment_name, :category, :quantity_available)');
            $stmt->execute([
                ':property_no'        => $property_no,
                ':equipment_name'     => $equipment_name,
                ':category'           => $category,
                ':quantity_available' => $quantity_available
            ]);
            redirectWithFlash('Equipment added successfully!');
        } catch (Exception $e) {
            redirectWithFlash('Error adding equipment: ' . friendlyError($e), 'error');
        }
    }
    
    // Update Equipment
    if ($action === 'update_equipment') {
        try {
            $equipment_id = postPositiveInt('equipment_id', 'Equipment ID');
            $property_no = postPropertyNo();
            $equipment_name = postText('equipment_name', 'Equipment name', 100);
            $category = postOptionalText('category', 'Category', 50);
            $quantity_available = postNonNegativeInt('quantity_available', 'Available quantity');
            $stmt = $pdo->prepare('UPDATE equipment SET property_no = :property_no, equipment_name = :equipment_name, category = :category, quantity_available = :quantity_available WHERE equipment_id = :equipment_id');
            $stmt->execute([
                ':property_no'        => $property_no,
                ':equipment_name'     => $equipment_name,
                ':category'           => $category,
                ':quantity_available' => $quantity_available,
                ':equipment_id'       => $equipment_id
            ]);
            if ($stmt->rowCount() === 0) {
                $stmtExists = $pdo->prepare('SELECT 1 FROM equipment WHERE equipment_id = :equipment_id');
                $stmtExists->execute([':equipment_id' => $equipment_id]);
                if (!$stmtExists->fetchColumn()) {
                    throw new RuntimeException('The selected equipment no longer exists.');
                }
            }
            redirectWithFlash('Equipment updated successfully!');
        } catch (Exception $e) {
            redirectWithFlash('Error updating equipment: ' . friendlyError($e), 'error');
        }
    }
    
    // Delete Equipment
    if ($action === 'delete_equipment') {
        try {
            $equipment_id = postPositiveInt('equipment_id', 'Equipment ID');
            $stmt = $pdo->prepare('DELETE FROM equipment WHERE equipment_id = :equipment_id');
            $stmt->execute([':equipment_id' => $equipment_id]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('The selected equipment no longer exists.');
            }
            redirectWithFlash('Equipment deleted successfully!');
        } catch (Exception $e) {
            redirectWithFlash('Error deleting equipment: ' . friendlyError($e), 'error');
        }
    }
}

// UPDATE BORROWER
if ($post_action === 'update_borrower') {
    try {
        $borrower_id = postPositiveInt('borrower_id', 'Borrower ID');
        $borrower_no = postBorrowerNo();
        $full_name = postText('full_name', 'Full name', 100);
        $borrower_type = postBorrowerType();
        $email = postOptionalEmail();

        $stmtCheck = $pdo->prepare('SELECT 1 FROM borrowers WHERE borrower_id = :borrower_id');
        $stmtCheck->execute([':borrower_id' => $borrower_id]);
        if (!$stmtCheck->fetchColumn()) {
            throw new RuntimeException('The selected borrower no longer exists.');
        }

        $stmtB = $pdo->prepare('UPDATE borrowers SET borrower_no = :borrower_no, full_name = :full_name, borrower_type = :borrower_type, email = :email WHERE borrower_id = :borrower_id');
        $stmtB->execute([
            ':borrower_no'   => $borrower_no,
            ':full_name'     => $full_name,
            ':borrower_type' => $borrower_type,
            ':email'         => $email,
            ':borrower_id'   => $borrower_id
        ]);

        redirectWithFlash('Borrower updated successfully.');
    } catch (Exception $e) {
        redirectWithFlash('Error updating borrower: ' . friendlyError($e), 'error');
    }
}

// DELETE BORROWER
if ($post_action === 'delete') {
    try {
        $pdo->beginTransaction();
        $borrower_id = postPositiveInt('borrower_id', 'Borrower ID');
        
        $stmtT = $pdo->prepare('SELECT borrow_id FROM borrow_transactions WHERE borrower_id = :id');
        $stmtT->execute([':id' => $borrower_id]);
        $transactions = $stmtT->fetchAll(PDO::FETCH_COLUMN);

        foreach ($transactions as $bid) {
            $pdo->prepare('DELETE FROM borrow_items WHERE borrow_id = :bid')->execute([':bid' => $bid]);
            $pdo->prepare('DELETE FROM borrow_transactions WHERE borrow_id = :bid')->execute([':bid' => $bid]);
        }
        
        $stmtBorrower = $pdo->prepare('DELETE FROM borrowers WHERE borrower_id = :id');
        $stmtBorrower->execute([':id' => $borrower_id]);
        if ($stmtBorrower->rowCount() === 0) {
            throw new RuntimeException('The selected borrower no longer exists.');
        }
        
        $pdo->commit();
        redirectWithFlash('Borrower deleted successfully!');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirectWithFlash('Error deleting borrower: ' . friendlyError($e), 'error');
    }
}

// CREATE OR REPLACE VIEWS (Rubric Criteria)
try {
    // Additive columns used by the newer forms/reports (safe to re-run on an existing database).
    $pdo->exec("
        ALTER TABLE borrowers ADD COLUMN IF NOT EXISTS email VARCHAR(120);
        ALTER TABLE equipment ADD COLUMN IF NOT EXISTS category VARCHAR(50);
    ");

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
            COUNT(DISTINCT bi.borrow_id) AS total_transactions,
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
} catch (Throwable $e) {
    error_log('Borrowing system view setup failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Borrowing system database setup failed. Check the application error log.');
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
            IF NOT FOUND OR p_requested_qty IS NULL OR p_requested_qty <= 0 THEN
                RETURN FALSE;
            END IF;
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
            v_status VARCHAR(20);
        BEGIN
            SELECT e.quantity_available, bt.status
            INTO v_available_qty, v_status
            FROM equipment e
            JOIN borrow_transactions bt ON bt.borrow_id = NEW.borrow_id
            WHERE e.equipment_id = NEW.equipment_id;
            IF (TG_OP = 'UPDATE' AND OLD.equipment_id = NEW.equipment_id) THEN
                v_already_held := OLD.quantity;
            END IF;
            IF lower(v_status) IN ('borrowed', 'overdue')
               AND NEW.quantity > v_available_qty + v_already_held THEN
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

    // 4. Audit Table & AFTER Audit Trigger (captures OLD and NEW quantities and the operator)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS borrow_items_audit (
            audit_id SERIAL PRIMARY KEY,
            borrow_id INT,
            equipment_id INT,
            quantity INT,
            old_quantity INT,
            new_quantity INT,
            action VARCHAR(10),
            changed_by VARCHAR(80),
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        ALTER TABLE borrow_items_audit ADD COLUMN IF NOT EXISTS old_quantity INT;
        ALTER TABLE borrow_items_audit ADD COLUMN IF NOT EXISTS new_quantity INT;
        ALTER TABLE borrow_items_audit ADD COLUMN IF NOT EXISTS changed_by VARCHAR(80);

        CREATE OR REPLACE FUNCTION fn_borrow_items_audit()
        RETURNS TRIGGER AS $$
        DECLARE
            v_actor VARCHAR(80);
        BEGIN
            v_actor := COALESCE(NULLIF(current_setting('wk5.actor', true), ''), 'Unidentified database operator');
            IF (TG_OP = 'INSERT') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, old_quantity, new_quantity, action, changed_by)
                VALUES (NEW.borrow_id, NEW.equipment_id, NEW.quantity, NULL, NEW.quantity, 'INSERT', v_actor);
            ELSIF (TG_OP = 'UPDATE') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, old_quantity, new_quantity, action, changed_by)
                VALUES (NEW.borrow_id, NEW.equipment_id, NEW.quantity, OLD.quantity, NEW.quantity, 'UPDATE', v_actor);
            ELSIF (TG_OP = 'DELETE') THEN
                INSERT INTO borrow_items_audit (borrow_id, equipment_id, quantity, old_quantity, new_quantity, action, changed_by)
                VALUES (OLD.borrow_id, OLD.equipment_id, OLD.quantity, OLD.quantity, NULL, 'DELETE', v_actor);
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
        DECLARE
            v_status VARCHAR(20);
            v_old_active BOOLEAN := FALSE;
            v_new_active BOOLEAN := FALSE;
        BEGIN
            IF (TG_OP = 'INSERT') THEN
                SELECT status INTO v_status FROM borrow_transactions WHERE borrow_id = NEW.borrow_id;
                IF lower(v_status) IN ('borrowed', 'overdue') THEN
                    UPDATE equipment SET quantity_available = quantity_available - NEW.quantity WHERE equipment_id = NEW.equipment_id;
                END IF;
            ELSIF (TG_OP = 'DELETE') THEN
                SELECT status INTO v_status FROM borrow_transactions WHERE borrow_id = OLD.borrow_id;
                IF lower(v_status) IN ('borrowed', 'overdue') THEN
                    UPDATE equipment SET quantity_available = quantity_available + OLD.quantity WHERE equipment_id = OLD.equipment_id;
                END IF;
            ELSIF (TG_OP = 'UPDATE') THEN
                SELECT status INTO v_status FROM borrow_transactions WHERE borrow_id = OLD.borrow_id;
                v_old_active := lower(v_status) IN ('borrowed', 'overdue');
                SELECT status INTO v_status FROM borrow_transactions WHERE borrow_id = NEW.borrow_id;
                v_new_active := lower(v_status) IN ('borrowed', 'overdue');
                IF v_old_active THEN
                    UPDATE equipment SET quantity_available = quantity_available + OLD.quantity WHERE equipment_id = OLD.equipment_id;
                END IF;
                IF v_new_active THEN
                    UPDATE equipment SET quantity_available = quantity_available - NEW.quantity WHERE equipment_id = NEW.equipment_id;
                END IF;
            END IF;
            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;

        DROP TRIGGER IF EXISTS trg_sync_equipment_stock ON borrow_items;
        CREATE TRIGGER trg_sync_equipment_stock
        AFTER INSERT OR UPDATE OR DELETE ON borrow_items
        FOR EACH ROW EXECUTE FUNCTION fn_sync_equipment_stock();

        CREATE OR REPLACE FUNCTION fn_sync_equipment_stock_on_status_change()
        RETURNS TRIGGER AS $$
        DECLARE
            v_item RECORD;
        BEGIN
            IF lower(OLD.status) IN ('borrowed', 'overdue')
               AND lower(NEW.status) NOT IN ('borrowed', 'overdue') THEN
                FOR v_item IN
                    SELECT equipment_id, SUM(quantity)::INT AS quantity
                    FROM borrow_items
                    WHERE borrow_id = NEW.borrow_id
                    GROUP BY equipment_id
                    ORDER BY equipment_id
                LOOP
                    UPDATE equipment
                    SET quantity_available = quantity_available + v_item.quantity
                    WHERE equipment_id = v_item.equipment_id;
                END LOOP;
            ELSIF lower(OLD.status) NOT IN ('borrowed', 'overdue')
                  AND lower(NEW.status) IN ('borrowed', 'overdue') THEN
                FOR v_item IN
                    SELECT equipment_id, SUM(quantity)::INT AS quantity
                    FROM borrow_items
                    WHERE borrow_id = NEW.borrow_id
                    GROUP BY equipment_id
                    ORDER BY equipment_id
                LOOP
                    UPDATE equipment
                    SET quantity_available = quantity_available - v_item.quantity
                    WHERE equipment_id = v_item.equipment_id
                      AND quantity_available >= v_item.quantity;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Not enough available stock to reactivate this borrowing transaction';
                    END IF;
                END LOOP;
            END IF;
            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;

        DROP TRIGGER IF EXISTS trg_sync_equipment_stock_on_status_change ON borrow_transactions;
        CREATE TRIGGER trg_sync_equipment_stock_on_status_change
        AFTER UPDATE OF status ON borrow_transactions
        FOR EACH ROW
        WHEN (OLD.status IS DISTINCT FROM NEW.status)
        EXECUTE FUNCTION fn_sync_equipment_stock_on_status_change();
    ");
} catch (Throwable $e) {
    error_log('Borrowing system database object setup failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Borrowing system database setup failed. Check the application error log.');
}

// FETCH EQUIPMENT FOR DROPDOWN & TABLE
$equipment_list = $pdo->query("
    SELECT equipment_id, property_no, equipment_name, category, quantity_available,
           CASE
               WHEN quantity_available > 5 THEN 'Available'
               WHEN quantity_available BETWEEN 1 AND 5 THEN 'Low Stock'
               ELSE 'Out of Stock'
           END AS availability_status
    FROM equipment
    ORDER BY equipment_name
")->fetchAll(PDO::FETCH_ASSOC);
$borrower_list = $pdo->query('SELECT borrower_id, borrower_no, full_name, borrower_type, email FROM borrowers ORDER BY borrower_no')->fetchAll(PDO::FETCH_ASSOC);
$transactions = $pdo->query("
    SELECT bt.borrow_id, bt.borrower_id, b.borrower_no, b.full_name, bt.borrow_date, bt.due_date, bt.status
    FROM borrow_transactions bt
    JOIN borrowers b ON b.borrower_id = bt.borrower_id
    ORDER BY bt.borrow_date DESC, bt.borrow_id DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($transactions as &$transaction) {
    $transaction['items'] = [];
}
unset($transaction);
$transaction_items = $pdo->query("
    SELECT bi.borrow_item_id, bi.borrow_id, bi.equipment_id, e.equipment_name, bi.quantity
    FROM borrow_items bi
    JOIN equipment e ON e.equipment_id = bi.equipment_id
    ORDER BY bi.borrow_id, bi.borrow_item_id
")->fetchAll(PDO::FETCH_ASSOC);
$transactions_by_id = [];
foreach ($transactions as $index => $transaction) {
    $transactions_by_id[$transaction['borrow_id']] = $index;
}
foreach ($transaction_items as $item) {
    if (isset($transactions_by_id[$item['borrow_id']])) {
        $transactions[$transactions_by_id[$item['borrow_id']]]['items'][] = $item;
    }
}

// Backend query using DISTINCT for Rubric Criteria 6
$distinct_types = $pdo->query("SELECT DISTINCT borrower_type AS unique_role FROM borrowers WHERE borrower_type IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$distinct_categories = $pdo->query("SELECT DISTINCT category FROM equipment WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

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
        COUNT(*) AS total_borrows,
        COUNT(*) FILTER (WHERE lower(bt.status) = 'returned') AS returned_count,
        COUNT(*) FILTER (WHERE lower(bt.status) IN ('borrowed', 'overdue')) AS active_count
    FROM borrow_transactions bt
    GROUP BY DATE_TRUNC('month', bt.borrow_date)
    ORDER BY DATE_TRUNC('month', bt.borrow_date) DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Reuse the created views inside the application (Rubric Criteria: Views + multiple INNER JOINs)
$vw_details = $pdo->query("SELECT borrower_no, full_name, equipment_name, quantity, borrow_date, due_date, status FROM vw_borrowing_details ORDER BY borrow_date DESC, full_name")->fetchAll(PDO::FETCH_ASSOC);
$vw_usage   = $pdo->query("SELECT property_no, equipment_name, total_transactions, total_quantity_borrowed, availability_status FROM vw_equipment_usage ORDER BY equipment_name")->fetchAll(PDO::FETCH_ASSOC);

// Exercise the CASE classifications at stock boundaries and transaction-status edge cases.
$case_classification_tests = $pdo->query("
    WITH cases (category, scenario, quantity_available, transaction_status, due_date, expected) AS (
        VALUES
            ('Availability', 'No stock', 0, NULL::text, NULL::date, 'Out of Stock'),
            ('Availability', 'One item available', 1, NULL::text, NULL::date, 'Low Stock'),
            ('Availability', 'Five items available', 5, NULL::text, NULL::date, 'Low Stock'),
            ('Availability', 'Six items available', 6, NULL::text, NULL::date, 'Available'),
            ('Transaction status', 'Explicitly overdue, due in future', NULL::integer, 'overdue', CURRENT_DATE + 1, 'Overdue'),
            ('Transaction status', 'Borrowed and past due', NULL::integer, 'borrowed', CURRENT_DATE - 1, 'Overdue'),
            ('Transaction status', 'Borrowed, due today', NULL::integer, 'borrowed', CURRENT_DATE, 'Borrowed'),
            ('Transaction status', 'Returned, past due', NULL::integer, 'returned', CURRENT_DATE - 1, 'Returned'),
            ('Transaction status', 'No transaction', NULL::integer, NULL::text, NULL::date, 'Not Borrowed')
    ),
    classified AS (
        SELECT category, scenario, expected,
               CASE
                   WHEN category = 'Availability' THEN
                       CASE
                           WHEN quantity_available > 5 THEN 'Available'
                           WHEN quantity_available BETWEEN 1 AND 5 THEN 'Low Stock'
                           ELSE 'Out of Stock'
                       END
                   ELSE
                       CASE
                           WHEN transaction_status = 'overdue'
                             OR (transaction_status = 'borrowed' AND due_date < CURRENT_DATE) THEN 'Overdue'
                           ELSE INITCAP(COALESCE(transaction_status, 'not borrowed'))
                       END
               END AS actual
        FROM cases
    )
    SELECT category, scenario, expected, actual, actual = expected AS passed
    FROM classified
    ORDER BY CASE category WHEN 'Availability' THEN 1 ELSE 2 END, scenario
")->fetchAll(PDO::FETCH_ASSOC);

// Show every allowed transaction status, including statuses with no records yet.
$status_summary = $pdo->query("
    SELECT statuses.status, COUNT(bt.borrow_id) AS total
    FROM (VALUES ('borrowed'), ('returned'), ('overdue')) AS statuses(status)
    LEFT JOIN borrow_transactions bt ON lower(bt.status) = statuses.status
    GROUP BY statuses.status
    ORDER BY CASE statuses.status
        WHEN 'borrowed' THEN 1
        WHEN 'returned' THEN 2
        WHEN 'overdue' THEN 3
    END
")->fetchAll(PDO::FETCH_ASSOC);

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
           COALESCE(SUM(bi.quantity), 0) AS total_items,
           STRING_AGG(DISTINCT e.equipment_name, ', ' ORDER BY e.equipment_name) AS equipment_used
    FROM borrowers b
    JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    LEFT JOIN borrow_items bi ON bi.borrow_id = bt.borrow_id
    LEFT JOIN equipment e ON e.equipment_id = bi.equipment_id
    GROUP BY b.borrower_type
    HAVING COUNT(DISTINCT bt.borrow_id) >= 2
    ORDER BY transaction_count DESC, b.borrower_type
")->fetchAll(PDO::FETCH_ASSOC);

// Aggregate functions per equipment: COUNT / SUM / AVG / MIN / MAX (+ ROUND, NULLIF)
$equip_aggregates = $pdo->query("
    SELECT e.property_no, e.equipment_name,
           COUNT(DISTINCT bi.borrow_id) AS times_borrowed,
           COALESCE(SUM(bi.quantity), 0) AS total_qty,
           ROUND(SUM(bi.quantity)::numeric / NULLIF(COUNT(DISTINCT bi.borrow_id), 0), 2) AS avg_qty,
           MIN(bi.quantity) AS min_qty,
           MAX(bi.quantity) AS max_qty
    FROM equipment e
    LEFT JOIN borrow_items bi ON e.equipment_id = bi.equipment_id
    GROUP BY e.equipment_id, e.property_no, e.equipment_name
    ORDER BY times_borrowed DESC, e.equipment_name
")->fetchAll(PDO::FETCH_ASSOC);

// Compare the same active and overdue borrower sets with UNION ALL and UNION.
$activity_union_all = $pdo->query("
    SELECT DISTINCT b.borrower_no, INITCAP(b.full_name) AS borrower
    FROM borrowers b JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    WHERE lower(bt.status) IN ('borrowed', 'overdue')
    UNION ALL
    SELECT DISTINCT b.borrower_no, INITCAP(b.full_name) AS borrower
    FROM borrowers b JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    WHERE lower(bt.status) IN ('borrowed', 'overdue')
      AND (lower(bt.status) = 'overdue' OR bt.due_date < CURRENT_DATE)
    ORDER BY borrower_no, borrower
")->fetchAll(PDO::FETCH_ASSOC);

// UNION removes a borrower row that appears in both compatible result sets.
$activity_union = $pdo->query("
    SELECT DISTINCT b.borrower_no, INITCAP(b.full_name) AS borrower
    FROM borrowers b JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    WHERE lower(bt.status) IN ('borrowed', 'overdue')
    UNION
    SELECT DISTINCT b.borrower_no, INITCAP(b.full_name) AS borrower
    FROM borrowers b JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    WHERE lower(bt.status) IN ('borrowed', 'overdue')
      AND (lower(bt.status) = 'overdue' OR bt.due_date < CURRENT_DATE)
    ORDER BY borrower_no, borrower
")->fetchAll(PDO::FETCH_ASSOC);

// Demonstrate the Week 10 functions with different inputs (one row per equipment item)
$fn_demo = $pdo->query("
    SELECT property_no, equipment_name, quantity_available,
           fn_check_equipment_availability(equipment_id, 1) AS can_lend_one,
           fn_check_equipment_availability(equipment_id, quantity_available + 1) AS can_lend_one_more_than_available,
           fn_total_borrowed(equipment_id) AS total_borrowed_all_time
    FROM equipment
    ORDER BY equipment_name
")->fetchAll(PDO::FETCH_ASSOC);

// Recent audit trail written automatically by the AFTER trigger
$audit_log = $pdo->query("
    SELECT audit_id, borrow_id, equipment_id, old_quantity, new_quantity, action, changed_by, changed_at
    FROM borrow_items_audit
    ORDER BY audit_id DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// --- Live, NON-DESTRUCTIVE self-tests: every scenario runs inside a transaction that is rolled back ---
$self_tests = [];
$transaction_rollback_test = null;
$ref_borrower = $pdo->query("SELECT borrower_id FROM borrowers ORDER BY borrower_id LIMIT 1")->fetchColumn();
$ref_equip    = $pdo->query("SELECT equipment_id FROM equipment WHERE quantity_available >= 2 ORDER BY equipment_id LIMIT 1")->fetchColumn();
$ref_property = $pdo->query("SELECT property_no FROM equipment ORDER BY equipment_id LIMIT 1")->fetchColumn();
$ref_initial_stock = $ref_equip
    ? (int)$pdo->query("SELECT quantity_available FROM equipment WHERE equipment_id = " . (int)$ref_equip)->fetchColumn()
    : null;
$ref_initial_borrowed = $ref_equip
    ? (int)$pdo->query("SELECT fn_total_borrowed(" . (int)$ref_equip . ")")->fetchColumn()
    : null;

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
                if (is_array($detail) && array_key_exists('passed', $detail) && array_key_exists('observed', $detail)) {
                    $observed = (string)$detail['observed'];
                    $ok = (bool)$detail['passed'];
                } else {
                    $observed = 'Accepted' . ($detail ? ' — ' . $detail : '');
                    $ok = ($want === 'accept');
                }
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

        $runTest('Availability function approves one item when stock exists', 'accept', function () use ($pdo, $ref_equip) {
            $stmt = $pdo->prepare('SELECT fn_check_equipment_availability(:equipment_id, 1)');
            $stmt->execute([':equipment_id' => $ref_equip]);
            $result = $stmt->fetchColumn();
            if (!in_array($result, [true, 't', '1', 1], true)) {
                throw new RuntimeException('The function did not approve a one-item request with available stock.');
            }
            return 'returned Yes for one available item';
        });

        $runTest('Availability function rejects a request above current stock', 'accept', function () use ($pdo, $ref_equip) {
            $stmt = $pdo->prepare("
                SELECT fn_check_equipment_availability(equipment_id, quantity_available + 1)
                FROM equipment
                WHERE equipment_id = :equipment_id
            ");
            $stmt->execute([':equipment_id' => $ref_equip]);
            $result = $stmt->fetchColumn();
            if (in_array($result, [true, 't', '1', 1], true)) {
                throw new RuntimeException('The function approved a request exceeding available stock.');
            }
            return 'returned No for a request one above available stock';
        });

        $runTest('Availability function rejects unknown equipment', 'accept', function () use ($pdo) {
            $result = $pdo->query('SELECT fn_check_equipment_availability(999999, 1)')->fetchColumn();
            if (in_array($result, [true, 't', '1', 1], true)) {
                throw new RuntimeException('The function approved an unknown equipment ID.');
            }
            return 'returned No for an unknown equipment ID';
        });

        $runTest('Availability function rejects nonpositive requests', 'accept', function () use ($pdo, $ref_equip) {
            $stmt = $pdo->prepare('SELECT fn_check_equipment_availability(:equipment_id, 0)');
            $stmt->execute([':equipment_id' => $ref_equip]);
            $result = $stmt->fetchColumn();
            if (in_array($result, [true, 't', '1', 1], true)) {
                throw new RuntimeException('The function approved a zero-quantity request.');
            }
            return 'returned No for a zero-quantity request';
        });

        $runTest('Insert a valid borrowing item (BEFORE trigger + audit + stock sync)', 'accept', function () use ($pdo, $test_borrow_id, $ref_equip, $ref_initial_stock, $ref_initial_borrowed) {
            $pdo->exec("INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES ($test_borrow_id, $ref_equip, 1)");
            $stmtAudit = $pdo->prepare("
                SELECT equipment_id, quantity, old_quantity, new_quantity
                FROM borrow_items_audit
                WHERE borrow_id = :borrow_id AND action = 'INSERT'
                ORDER BY audit_id DESC
                LIMIT 1
            ");
            $stmtAudit->execute([':borrow_id' => $test_borrow_id]);
            $audit = $stmtAudit->fetch(PDO::FETCH_ASSOC);
            $auditCount = (int)$pdo->query("SELECT COUNT(*) FROM borrow_items_audit WHERE borrow_id = $test_borrow_id AND action = 'INSERT'")->fetchColumn();
            $stock = (int)$pdo->query("SELECT quantity_available FROM equipment WHERE equipment_id = $ref_equip")->fetchColumn();
            $borrowed = (int)$pdo->query("SELECT fn_total_borrowed($ref_equip)")->fetchColumn();
            if (!$audit || $auditCount !== 1 || (int)$audit['equipment_id'] !== (int)$ref_equip
                || (int)$audit['quantity'] !== 1 || $audit['old_quantity'] !== null
                || (int)$audit['new_quantity'] !== 1
                || $stock !== $ref_initial_stock - 1 || $borrowed !== $ref_initial_borrowed + 1) {
                throw new RuntimeException('Insert did not consistently update audit, available stock, and borrowed total.');
            }
            return 'one INSERT audit row recorded the equipment and new quantity 1; stock decreased by 1; borrowed total increased by 1';
        });

        $runTest('Insert exceeding available stock is rejected without side effects', 'reject', function () use ($pdo, $test_borrow_id, $ref_equip, $ref_initial_stock, $ref_initial_borrowed) {
            $savepoint = 'overstock_insert_validation_check';
            $pdo->exec("SAVEPOINT $savepoint");
            $rejectedByValidationTrigger = false;
            try {
                $stmtInvalidInsert = $pdo->prepare('INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES (:borrow_id, :equipment_id, 999999)');
                $stmtInvalidInsert->execute([':borrow_id' => $test_borrow_id, ':equipment_id' => $ref_equip]);
            } catch (PDOException $e) {
                $rejectedByValidationTrigger = ($e->errorInfo[0] ?? null) === 'P0001';
            }
            $pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");
            $pdo->exec("RELEASE SAVEPOINT $savepoint");

            $stock = (int)$pdo->query("SELECT quantity_available FROM equipment WHERE equipment_id = $ref_equip")->fetchColumn();
            $borrowed = (int)$pdo->query("SELECT fn_total_borrowed($ref_equip)")->fetchColumn();
            $itemCount = (int)$pdo->query("SELECT COUNT(*) FROM borrow_items WHERE borrow_id = $test_borrow_id AND equipment_id = $ref_equip")->fetchColumn();
            $auditCount = (int)$pdo->query("SELECT COUNT(*) FROM borrow_items_audit WHERE borrow_id = $test_borrow_id AND equipment_id = $ref_equip")->fetchColumn();
            $passed = $rejectedByValidationTrigger && $stock === $ref_initial_stock - 1
                && $borrowed === $ref_initial_borrowed + 1 && $itemCount === 1 && $auditCount === 1;
            return [
                'passed' => $passed,
                'observed' => $passed
                    ? 'Rejected by BEFORE trigger; stock, borrowed total, item row, and INSERT audit remained unchanged'
                    : 'Expected trigger rejection or unchanged stock/borrowed total/item/audit state was not confirmed',
            ];
        });

        $runTest('BEFORE UPDATE rejects excess quantity and preserves existing item', 'accept', function () use ($pdo, $test_borrow_id, $ref_equip) {
            $stmtOriginal = $pdo->prepare('SELECT quantity FROM borrow_items WHERE borrow_id = :borrow_id AND equipment_id = :equipment_id');
            $stmtOriginal->execute([':borrow_id' => $test_borrow_id, ':equipment_id' => $ref_equip]);
            $originalQuantity = $stmtOriginal->fetchColumn();
            if ($originalQuantity === false) {
                throw new RuntimeException('The valid test item is missing before the update check.');
            }

            $savepoint = 'before_update_validation_check';
            $pdo->exec("SAVEPOINT $savepoint");
            try {
                $stmtInvalidUpdate = $pdo->prepare('UPDATE borrow_items SET quantity = 999999 WHERE borrow_id = :borrow_id AND equipment_id = :equipment_id');
                $stmtInvalidUpdate->execute([':borrow_id' => $test_borrow_id, ':equipment_id' => $ref_equip]);
                $pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");
                $pdo->exec("RELEASE SAVEPOINT $savepoint");
                throw new RuntimeException('The BEFORE UPDATE trigger accepted a quantity above available stock.');
            } catch (PDOException $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");
                $pdo->exec("RELEASE SAVEPOINT $savepoint");
                if (($e->errorInfo[0] ?? null) !== 'P0001') {
                    throw $e;
                }
            }

            $stmtAfter = $pdo->prepare('SELECT quantity FROM borrow_items WHERE borrow_id = :borrow_id AND equipment_id = :equipment_id');
            $stmtAfter->execute([':borrow_id' => $test_borrow_id, ':equipment_id' => $ref_equip]);
            $quantityAfter = $stmtAfter->fetchColumn();
            if ((string)$quantityAfter !== (string)$originalQuantity) {
                throw new RuntimeException('The rejected update changed the existing borrowed quantity.');
            }
            return 'over-stock update was rejected; existing quantity remained ' . $originalQuantity;
        });

        $runTest('Same equipment twice in one transaction (unique)', 'reject', function () use ($pdo, $test_borrow_id, $ref_equip) {
            $pdo->exec("INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES ($test_borrow_id, $ref_equip, 1)");
        });

        $runTest('Borrow item referencing unknown equipment (foreign key)', 'reject', function () use ($pdo, $test_borrow_id) {
            $pdo->exec("INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES ($test_borrow_id, 999999, 1)");
        });

        $runTest('Borrow item with a negative quantity (check)', 'reject', function () use ($pdo, $test_borrow_id, $ref_equip) {
            $stmt = $pdo->prepare("
                INSERT INTO borrow_items (borrow_id, equipment_id, quantity)
                VALUES (:borrow_id, :equipment_id, -1)
            ");
            $stmt->execute([':borrow_id' => $test_borrow_id, ':equipment_id' => $ref_equip]);
        });

        $runTest('Set available stock below zero (check)', 'reject', function () use ($pdo, $ref_equip) {
            $pdo->exec("UPDATE equipment SET quantity_available = -1 WHERE equipment_id = $ref_equip");
        });

        $runTest('Duplicate borrower primary key', 'reject', function () use ($pdo, $ref_borrower) {
            $stmt = $pdo->prepare("
                INSERT INTO borrowers (borrower_id, borrower_no, full_name, borrower_type)
                VALUES (:borrower_id, :borrower_no, 'Constraint Test', 'student')
            ");
            $stmt->execute([
                ':borrower_id' => $ref_borrower,
                ':borrower_no' => 'TST-' . strtoupper(bin2hex(random_bytes(6))),
            ]);
        });

        $runTest('Missing required borrower name (not-null)', 'reject', function () use ($pdo) {
            $stmt = $pdo->prepare("
                INSERT INTO borrowers (borrower_no, full_name, borrower_type)
                VALUES (:borrower_no, NULL, 'student')
            ");
            $stmt->execute([':borrower_no' => 'TST-' . strtoupper(bin2hex(random_bytes(6)))]);
        });

        if ($ref_property !== null && $ref_property !== '') {
            $safe_prop = $pdo->quote($ref_property);
            $runTest('Add equipment with a duplicate property number', 'reject', function () use ($pdo, $safe_prop) {
                $pdo->exec("INSERT INTO equipment (property_no, equipment_name, quantity_available) VALUES ($safe_prop, 'Duplicate Test', 1)");
            });
        }

        $runTest('Update item quantity (audit + stock sync + borrowed total)', 'accept', function () use ($pdo, $test_borrow_id, $ref_equip, $ref_initial_stock, $ref_initial_borrowed) {
            $pdo->exec("UPDATE borrow_items SET quantity = 2 WHERE borrow_id = $test_borrow_id");
            $row = $pdo->query("SELECT old_quantity, new_quantity FROM borrow_items_audit WHERE borrow_id = $test_borrow_id AND action = 'UPDATE' ORDER BY audit_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $stock = (int)$pdo->query("SELECT quantity_available FROM equipment WHERE equipment_id = $ref_equip")->fetchColumn();
            $borrowed = (int)$pdo->query("SELECT fn_total_borrowed($ref_equip)")->fetchColumn();
            if (!$row || (int)$row['old_quantity'] !== 1 || (int)$row['new_quantity'] !== 2
                || $stock !== $ref_initial_stock - 2 || $borrowed !== $ref_initial_borrowed + 2) {
                throw new RuntimeException('Update did not consistently record old/new quantities and reconcile stock and borrowed total.');
            }
            return "audit recorded 1 to 2; stock decreased by 2; borrowed total increased by 2";
        });

        $runTest('Delete item (audit + stock restoration + borrowed total)', 'accept', function () use ($pdo, $test_borrow_id, $ref_equip, $ref_initial_stock, $ref_initial_borrowed) {
            $pdo->exec("DELETE FROM borrow_items WHERE borrow_id = $test_borrow_id");
            $row = $pdo->query("SELECT old_quantity, new_quantity FROM borrow_items_audit WHERE borrow_id = $test_borrow_id AND action = 'DELETE' ORDER BY audit_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $stock = (int)$pdo->query("SELECT quantity_available FROM equipment WHERE equipment_id = $ref_equip")->fetchColumn();
            $borrowed = (int)$pdo->query("SELECT fn_total_borrowed($ref_equip)")->fetchColumn();
            if (!$row || (int)$row['old_quantity'] !== 2 || $row['new_quantity'] !== null
                || $stock !== $ref_initial_stock || $borrowed !== $ref_initial_borrowed) {
                throw new RuntimeException('Delete did not record the old quantity and restore stock and borrowed total.');
            }
            return 'audit recorded deleted quantity 2; stock and borrowed total returned to baseline';
        });

        $runTest('Status changes sync stock once without duplicate audit events', 'accept', function () use ($pdo, $ref_borrower, $ref_equip, $ref_initial_stock) {
            $stmtTransaction = $pdo->prepare("
                INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date, status)
                VALUES (:borrower_id, CURRENT_DATE, CURRENT_DATE + 2, 'returned')
                RETURNING borrow_id
            ");
            $stmtTransaction->execute([':borrower_id' => $ref_borrower]);
            $borrowId = (int)$stmtTransaction->fetchColumn();
            $stmtItem = $pdo->prepare('INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES (:borrow_id, :equipment_id, 1)');
            $stmtItem->execute([':borrow_id' => $borrowId, ':equipment_id' => $ref_equip]);
            $stockReturnedStart = (int)$pdo->query("SELECT quantity_available FROM equipment WHERE equipment_id = $ref_equip")->fetchColumn();

            $stmtStatus = $pdo->prepare('UPDATE borrow_transactions SET status = :status WHERE borrow_id = :borrow_id');
            $stmtStatus->execute([':status' => 'borrowed', ':borrow_id' => $borrowId]);
            $stockBorrowed = (int)$pdo->query("SELECT quantity_available FROM equipment WHERE equipment_id = $ref_equip")->fetchColumn();
            $stmtStatus->execute([':status' => 'returned', ':borrow_id' => $borrowId]);
            $stockReturned = (int)$pdo->query("SELECT quantity_available FROM equipment WHERE equipment_id = $ref_equip")->fetchColumn();
            $auditCount = (int)$pdo->query("SELECT COUNT(*) FROM borrow_items_audit WHERE borrow_id = $borrowId AND action = 'INSERT'")->fetchColumn();

            if ($stockReturnedStart !== $ref_initial_stock || $stockBorrowed !== $ref_initial_stock - 1
                || $stockReturned !== $ref_initial_stock || $auditCount !== 1) {
                throw new RuntimeException('Status changes did not sync stock exactly once or created unexpected audit rows.');
            }
            return 'Returned kept stock; Borrowed reduced it once; Returned restored it; one item INSERT was audited';
        });

        $rollbackSavepoint = 'transaction_rollback_test';
        $pdo->exec("SAVEPOINT $rollbackSavepoint");
        $rollbackBorrowId = null;
        $rollbackFailure = false;
        $rollbackItemAttempted = false;
        try {
            $stmtRollbackHeader = $pdo->prepare("
                INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date, status)
                VALUES (:borrower_id, CURRENT_DATE, CURRENT_DATE + 2, 'borrowed')
                RETURNING borrow_id
            ");
            $stmtRollbackHeader->execute([':borrower_id' => $ref_borrower]);
            $rollbackBorrowId = $stmtRollbackHeader->fetchColumn();

            $stmtInvalidItem = $pdo->prepare("
                INSERT INTO borrow_items (borrow_id, equipment_id, quantity)
                VALUES (:borrow_id, -1, 1)
            ");
            $rollbackItemAttempted = true;
            $stmtInvalidItem->execute([':borrow_id' => $rollbackBorrowId]);
        } catch (Exception $e) {
            $rollbackFailure = true;
        }
        $pdo->exec("ROLLBACK TO SAVEPOINT $rollbackSavepoint");
        $stmtHeaderCheck = $pdo->prepare('SELECT COUNT(*) FROM borrow_transactions WHERE borrow_id = :borrow_id');
        $stmtHeaderCheck->execute([':borrow_id' => $rollbackBorrowId]);
        $headerRemoved = (int)$stmtHeaderCheck->fetchColumn() === 0;
        $pdo->exec("RELEASE SAVEPOINT $rollbackSavepoint");
        $transaction_rollback_test = [
            'expected' => 'The failed item insert rolls back the transaction header; no partial borrowing remains.',
            'observed' => $rollbackFailure && $rollbackItemAttempted && $headerRemoved
                ? 'Invalid equipment was rejected and the temporary transaction header was removed.'
                : 'The forced failure did not leave the database in the expected rolled-back state.',
            'passed' => $rollbackFailure && $rollbackItemAttempted && $headerRemoved,
        ];
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
$search_name = $_GET['search_name'] ?? '';
$search_name = is_string($search_name) ? trim($search_name) : '';
$search_name = mb_substr($search_name, 0, 100, 'UTF-8');
$filter_type_input = $_GET['filter_type'] ?? '';
$filter_type = is_string($filter_type_input) ? strtolower(trim($filter_type_input)) : '';
$filter_status_input = $_GET['filter_status'] ?? '';
$filter_status = is_string($filter_status_input) ? strtolower(trim($filter_status_input)) : '';
$filter_type   = in_array($filter_type, ['student', 'faculty', 'staff'], true) ? $filter_type : '';
$filter_status = in_array($filter_status, ['borrowed', 'returned', 'overdue'], true) ? $filter_status : '';
$filter_category_input = $_GET['filter_category'] ?? '';
$filter_category = is_string($filter_category_input) ? trim($filter_category_input) : '';
$filter_category = in_array($filter_category, $distinct_categories, true) ? $filter_category : '';
$date_from_input = $_GET['date_from'] ?? '';
$date_from = validDate($date_from_input) ? $date_from_input : '';
$date_to_input = $_GET['date_to'] ?? '';
$date_to = validDate($date_to_input) ? $date_to_input : '';
$sort_by_input = $_GET['sort_by'] ?? 'b.borrower_id';
$sort_by = is_string($sort_by_input) ? $sort_by_input : 'b.borrower_id';
$sort_dir_input = $_GET['sort_dir'] ?? 'DESC';
$sort_dir = is_string($sort_dir_input) ? strtoupper($sort_dir_input) : 'DESC';

$allowed_columns = ['b.borrower_id', 'b.full_name', 'bt.borrow_date', 'bt.due_date'];
if (!in_array($sort_by, $allowed_columns, true)) {
    $sort_by = 'b.borrower_id';
}
if (!in_array($sort_dir, ['ASC', 'DESC'], true)) {
    $sort_dir = 'DESC';
}

// --- 2. JOINS, SQL FUNCTIONS, & CONDITIONAL SQL ---
$query = "
    SELECT
        b.borrower_id AS id, b.borrower_id AS borrower_id,
        b.borrower_no AS student_no, b.borrower_no AS borrower_no,
        bt.borrow_id AS borrow_id,
        UPPER(b.full_name) AS full_name,
        b.borrower_type AS role, b.borrower_type AS borrower_type,
        COALESCE(e.equipment_name, 'N/A') AS equipment_name,
        bi.quantity,
        bt.borrow_date,
        bt.due_date,
        ABS(CURRENT_DATE - bt.due_date) AS days_difference,
        CASE
            WHEN lower(bt.status) = 'overdue'
              OR (lower(bt.status) = 'borrowed' AND bt.due_date < CURRENT_DATE) THEN 'Overdue'
            ELSE INITCAP(COALESCE(bt.status, 'not borrowed'))
        END AS status_check
    FROM borrowers b
    LEFT JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    LEFT JOIN borrow_items bi ON bt.borrow_id = bi.borrow_id
    LEFT JOIN equipment e ON bi.equipment_id = e.equipment_id
";

$params = [];
$conditions = ['1 = 1'];
if ($search_name !== '') {
    $conditions[] = 'b.full_name ILIKE :search';
    $params[':search'] = "%$search_name%";
}
if ($filter_type !== '') {
    $conditions[] = 'b.borrower_type = :type';
    $params[':type'] = $filter_type;
}
if ($filter_status !== '') {
    if ($filter_status === 'overdue') {
        $conditions[] = "(lower(bt.status) = 'overdue' OR (lower(bt.status) = 'borrowed' AND bt.due_date < CURRENT_DATE))";
    } else {
        $conditions[] = 'lower(bt.status) = :status';
        $params[':status'] = $filter_status;
    }
}
if ($filter_category !== '') {
    $conditions[] = 'e.category = :category';
    $params[':category'] = $filter_category;
}
if ($date_from) {
    $conditions[] = 'bt.borrow_date >= :date_from';
    $params[':date_from'] = $date_from;
}
if ($date_to) {
    $conditions[] = 'bt.borrow_date <= :date_to';
    $params[':date_to'] = $date_to;
}

$query .= "\nWHERE " . implode("\n  AND ", $conditions);
$query .= "\nORDER BY $sort_by $sort_dir, b.full_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. AT-A-GLANCE SUMMARY (KPIs + de-duplicated per-type breakdown) ---
$kpi = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM borrowers) AS total_borrowers,
        (SELECT COUNT(*) FROM borrow_transactions WHERE lower(status) IN ('borrowed', 'overdue')) AS active_borrowings,
        (SELECT COALESCE(SUM(bi.quantity), 0)
           FROM borrow_items bi
           JOIN borrow_transactions bt ON bt.borrow_id = bi.borrow_id
          WHERE lower(bt.status) IN ('borrowed', 'overdue')) AS items_out,
        (SELECT COUNT(*) FROM borrow_transactions
          WHERE lower(status) IN ('borrowed', 'overdue') AND (lower(status) = 'overdue' OR due_date < CURRENT_DATE)) AS overdue
")->fetch(PDO::FETCH_ASSOC);

$summary_records = $pdo->query("
    SELECT
        borrower_types.borrower_type,
        COUNT(DISTINCT b.borrower_id) AS borrowers,
        COUNT(DISTINCT CASE WHEN lower(bt.status) IN ('borrowed', 'overdue') THEN bt.borrow_id END) AS borrowings,
        COALESCE(SUM(CASE WHEN lower(bt.status) IN ('borrowed', 'overdue') THEN bi.quantity ELSE 0 END), 0) AS items,
        COUNT(DISTINCT CASE WHEN lower(bt.status) IN ('borrowed', 'overdue') AND (lower(bt.status) = 'overdue' OR bt.due_date < CURRENT_DATE) THEN bt.borrow_id END) AS overdue
    FROM (VALUES ('student'), ('faculty'), ('staff')) AS borrower_types(borrower_type)
    LEFT JOIN borrowers b ON b.borrower_type = borrower_types.borrower_type
    LEFT JOIN borrow_transactions bt ON b.borrower_id = bt.borrower_id
    LEFT JOIN borrow_items bi ON bt.borrow_id = bi.borrow_id
    GROUP BY borrower_types.borrower_type
    ORDER BY CASE borrower_types.borrower_type
        WHEN 'student' THEN 1
        WHEN 'faculty' THEN 2
        WHEN 'staff' THEN 3
    END
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>University Property Borrowing System</title>
    <link rel="stylesheet" href="wk5_database2.css">
 </head>
 <body>
     <h1>University Property Borrowing System</h1>

    <?php if (isset($_SESSION['flash'])):
        $flash = $_SESSION['flash'];
        $flash_type = $flash['type'] === 'error' ? 'error' : 'success';
        $flash_role = $flash_type === 'error' ? 'alert' : 'status';
        unset($_SESSION['flash']);
    ?>
        <div class="alert alert-<?= $flash_type ?>" role="<?= $flash_role ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <nav class="module-tabs" role="tablist" aria-label="System modules">
        <button type="button" id="tab-overview" role="tab" aria-controls="module-overview" aria-selected="true" data-module-tab="overview">Overview</button>
        <button type="button" id="tab-borrowing" role="tab" aria-controls="module-borrowing" aria-selected="false" data-module-tab="borrowing" tabindex="-1">Borrowing</button>
        <button type="button" id="tab-records" role="tab" aria-controls="module-records" aria-selected="false" data-module-tab="records" tabindex="-1">Records</button>
        <button type="button" id="tab-borrowers" role="tab" aria-controls="module-borrowers" aria-selected="false" data-module-tab="borrowers" tabindex="-1">Borrowers</button>
        <button type="button" id="tab-equipment" role="tab" aria-controls="module-equipment" aria-selected="false" data-module-tab="equipment" tabindex="-1">Equipment</button>
        <button type="button" id="tab-reports" role="tab" aria-controls="module-reports" aria-selected="false" data-module-tab="reports" tabindex="-1">Reports</button>
    </nav>

    <section class="module-panel" id="module-overview" role="tabpanel" aria-labelledby="tab-overview" data-module-panel="overview">
    <div class="card" id="database-connection">
        <h2>Database Connection</h2>
        <p role="status"><strong>Connected</strong> to PostgreSQL.</p>
        <dl class="connection-details">
            <div>
                <dt>Connection mode</dt>
                <dd><?= $is_local_database ? 'Local development' : 'Remote host configured' ?></dd>
            </div>
            <div>
                <dt>Configured host</dt>
                <dd><?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?></dd>
            </div>
            <div>
                <dt>Port</dt>
                <dd><?= htmlspecialchars($port, ENT_QUOTES, 'UTF-8') ?></dd>
            </div>
            <div>
                <dt>Database</dt>
                <dd><?= htmlspecialchars($connection_info['database_name'], ENT_QUOTES, 'UTF-8') ?></dd>
            </div>
            <div>
                <dt>PostgreSQL version</dt>
                <dd><?= htmlspecialchars($connection_info['server_version'], ENT_QUOTES, 'UTF-8') ?></dd>
            </div>
        </dl>
        <?php if ($is_local_database): ?>
            <p class="connection-note">This currently connects to PostgreSQL on the same computer. To meet the remote-architecture requirement, set the <code>DB_HOST</code> environment variable to the separate database computer's private network address.</p>
        <?php else: ?>
            <p class="connection-note">Confirm that this PostgreSQL server is on a separate computer and that its firewall and <code>pg_hba.conf</code> allow connections only from this XAMPP server. Do not expose port 5432 to the public internet.</p>
        <?php endif; ?>
    </div>

    <!-- Summary Section: at-a-glance KPIs + per-type breakdown -->
    <div class="card" id="summary">
        <h2>System Summary</h2>
        <p class="report-note">Borrower totals are shown for every registered type, including categories with no current borrowers.</p>
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
    </section>

    <section class="module-panel" id="module-borrowing" role="tabpanel" aria-labelledby="tab-borrowing" data-module-panel="borrowing" hidden>
    <div class="card" id="create">
        <h2 id="transaction-form-heading">Create Borrowing Transaction</h2>
        <p class="workflow-note">Workflow: register the borrower in <strong>Borrowers</strong> and add available stock in <strong>Equipment</strong> first. Then select the borrower, dates, status, and one or more equipment items below.</p>
        <p class="report-note">The transaction header and all item rows are saved atomically: any failed item write rolls back the whole operation.</p>
        <?php if ($borrower_list && $equipment_list): ?>
        <form method="post" id="transaction-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create_transaction">
            <input type="hidden" name="borrow_id" value="">
            <div class="form-grid transaction-fields">
                <label class="field">Borrower
                <select name="borrower_id" required>
                    <option value="">-- Select Borrower --</option>
                    <?php foreach ($borrower_list as $borrower): ?>
                        <option value="<?= (int)$borrower['borrower_id'] ?>"><?= htmlspecialchars($borrower['borrower_no'] . ' — ' . $borrower['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                </label>
                <label class="field">Borrow date <input type="date" name="borrow_date" value="<?= date('Y-m-d') ?>" required></label>
                <label class="field">Due date <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" required></label>
                <label class="field">Status
                <select name="status" required>
                    <option value="borrowed" selected>Borrowed</option>
                    <option value="returned">Returned</option>
                    <option value="overdue">Overdue</option>
                </select>
                </label>
            </div>
            <p class="report-note item-section-label">Equipment items attached to this transaction:</p>
            <div id="items-container">
                <div class="item-row">
                    <input type="hidden" name="borrow_item_id[]" value="">
                    <label class="field">Equipment
                    <select name="equipment_id[]" required>
                        <option value="">-- Equipment --</option>
                        <?php foreach ($equipment_list as $item): ?>
                            <option value="<?= (int)$item['equipment_id'] ?>"><?= htmlspecialchars($item['equipment_name'] . ' (available: ' . $item['quantity_available'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    </label>
                    <label class="field">Quantity <input type="number" name="quantity[]" value="1" min="1" required></label>
                    <button type="button" class="delete" onclick="removeItemRow(this)" aria-label="Remove this equipment row">Remove item</button>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="button-muted" onclick="addItemRow()">+ Add Equipment Item</button>
                <button type="submit" id="transaction-submit">Create Transaction</button>
                <button type="button" id="transaction-cancel-edit" class="button-muted is-hidden" onclick="resetTransactionForm()">Cancel Edit</button>
            </div>
        </form>
        <?php else: ?>
            <p class="report-note">Register at least one borrower and add equipment before creating a transaction.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Transaction Management Check</h2>
        <p class="report-note">Create, update, and delete workflows run related header/item writes inside a database transaction, committing only after all writes succeed and rolling back on errors. The check below forces an invalid item insert after a temporary header insert, then verifies the header was rolled back. All test work is rolled back and no borrowing data is retained.</p>
        <?php if ($transaction_rollback_test !== null): ?>
        <table>
            <thead><tr><th>Test Scenario</th><th>Expected Behavior</th><th>Observed Result</th><th>Result</th></tr></thead>
            <tbody>
                <tr>
                    <td>Failed multi-step borrowing transaction</td>
                    <td><?= htmlspecialchars($transaction_rollback_test['expected'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($transaction_rollback_test['observed'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge <?= $transaction_rollback_test['passed'] ? 'badge-ontime' : 'badge-overdue' ?>"><?= $transaction_rollback_test['passed'] ? 'PASS' : 'FAIL' ?></span></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">Rollback test unavailable because no borrower and equipment records are available.</p>
        <?php endif; ?>
    </div>
    </section>

    <section class="module-panel" id="module-borrowers" role="tabpanel" aria-labelledby="tab-borrowers" data-module-panel="borrowers" hidden>
    <div class="card">
        <h2>Register Borrower</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create_borrower">
            <div class="form-grid borrower-fields">
                <label class="field">Borrower number <input type="text" name="borrower_no" maxlength="20" placeholder="B-0000" pattern="B-[0-9]{4}" required></label>
                <label class="field">Full name <input type="text" name="full_name" maxlength="100" required></label>
                <label class="field">Email (optional) <input type="email" name="email" maxlength="120"></label>
                <label class="field">Borrower type
                <select name="borrower_type" required>
                    <option value="">-- Type --</option>
                    <option value="student">Student</option>
                    <option value="faculty">Faculty</option>
                    <option value="staff">Staff</option>
                </select>
                </label>
                <button type="submit">Register</button>
            </div>
        </form>
    </div>

    <div class="card" id="borrowers">
        <h2>Borrower Directory</h2>
        <?php if ($borrower_list): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Borrower No.</th>
                    <th>Full Name</th>
                    <th>Type</th>
                    <th>Email</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($borrower_list as $borrower): ?>
                <tr>
                    <td><?= (int)$borrower['borrower_id'] ?></td>
                    <td><?= htmlspecialchars($borrower['borrower_no'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($borrower['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(ucfirst($borrower['borrower_type']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($borrower['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <div class="action-group">
                            <button type="button" class="btn-edit"
                                onclick='fillBorrowerUpdate(<?= json_encode((int)$borrower['borrower_id']) ?>, <?= json_encode($borrower['borrower_no'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>, <?= json_encode($borrower['full_name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>, <?= json_encode($borrower['borrower_type'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>, <?= json_encode($borrower['email'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                                Edit
                            </button>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="borrower_id" value="<?= (int)$borrower['borrower_id'] ?>">
                                <button type="submit" class="delete" onclick="return confirm('Delete this borrower and all of their borrowing records?')">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">No borrowers have been registered yet.</p>
        <?php endif; ?>
    </div>

    <div class="card" id="borrower-update-card">
        <h2>Update Borrower</h2>
        <p class="report-note">Choose <strong>Edit</strong> in the borrower directory to load a record into this form.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update_borrower">
            <div class="form-grid update-fields">
                <label class="field">Borrower ID <input type="number" name="borrower_id" id="upd_borrower_id" readonly required></label>
                <label class="field">Borrower number <input type="text" name="borrower_no" id="upd_borrower_no" maxlength="20" placeholder="B-0000" pattern="B-[0-9]{4}" required></label>
                <label class="field">Full name <input type="text" name="full_name" id="upd_full_name" maxlength="100" required></label>
                <label class="field">Email (optional) <input type="email" name="email" id="upd_email" maxlength="120"></label>
                <label class="field">Borrower type
                <select name="borrower_type" id="upd_borrower_type" required>
                    <option value="">-- Type --</option>
                    <option value="student">Student</option>
                    <option value="faculty">Faculty</option>
                    <option value="staff">Staff</option>
                </select>
                </label>
            </div>
            <button type="submit" class="form-button-full button-success">Update Borrower</button>
        </form>
    </div>
    </section>

    <section class="module-panel" id="module-records" role="tabpanel" aria-labelledby="tab-records" data-module-panel="records" hidden>
    <div class="card" id="filter">
        <h2>Search, Filter &amp; Sort Transactions</h2>
        <form method="get">
            <input type="hidden" name="module" value="records">
            <div class="form-grid search-fields">
            <label class="field">Borrower name <input type="search" name="search_name" value="<?= htmlspecialchars($search_name, ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="field">Borrower type
            <select name="filter_type">
                <option value="">-- All Types --</option>
                <option value="student" <?= $filter_type === 'student' ? 'selected' : '' ?>>Student</option>
                <option value="faculty" <?= $filter_type === 'faculty' ? 'selected' : '' ?>>Faculty</option>
                <option value="staff" <?= $filter_type === 'staff' ? 'selected' : '' ?>>Staff</option>
            </select>
            </label>
            <label class="field">Equipment category
            <select name="filter_category">
                <option value="">-- All Categories --</option>
                <?php foreach ($distinct_categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_category === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            </label>
            <label class="field">Transaction status
            <select name="filter_status">
                <option value="">-- All Statuses --</option>
                <option value="borrowed" <?= $filter_status === 'borrowed' ? 'selected' : '' ?>>Borrowed</option>
                <option value="returned" <?= $filter_status === 'returned' ? 'selected' : '' ?>>Returned</option>
                <option value="overdue" <?= $filter_status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
            </select>
            </label>
            <label class="field">Borrow date from <input type="date" name="date_from" value="<?= htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="field">Borrow date to <input type="date" name="date_to" value="<?= htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="field">Sort by
            <select name="sort_by">
                <option value="b.borrower_id" <?= $sort_by === 'b.borrower_id' ? 'selected' : '' ?>>Borrower ID</option>
                <option value="b.full_name" <?= $sort_by === 'b.full_name' ? 'selected' : '' ?>>Borrower name</option>
                <option value="bt.borrow_date" <?= $sort_by === 'bt.borrow_date' ? 'selected' : '' ?>>Borrow date</option>
            </select>
            </label>
            <label class="field">Order
            <select name="sort_dir">
                <option value="DESC" <?= $sort_dir === 'DESC' ? 'selected' : '' ?>>Descending</option>
                <option value="ASC" <?= $sort_dir === 'ASC' ? 'selected' : '' ?>>Ascending</option>
            </select>
            </label>
            </div>
            <div class="form-actions">
                <button type="submit">Apply filters</button>
                <a class="button-link" href="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') ?>?module=records">Reset filters</a>
            </div>
        </form>
    </div>

    <div class="card" id="filtered-results">
        <h2>Filtered Borrower and Transaction Results</h2>
        <p class="report-note">
            <?= count($records) ?> matching row<?= count($records) === 1 ? '' : 's' ?>.
            Rows match all selected filters; overdue includes transactions marked Overdue or Borrowed past their due date.
            Results use the selected sort field and direction, with borrower name A–Z as a tie-breaker.
            Absolute days from the due date are calculated with <code>ABS()</code>; the value is a distance, while status indicates the transaction state.
        </p>
        <?php if ($records): ?>
        <table>
            <thead>
                <tr>
                    <th>Borrower No.</th>
                    <th>Borrower</th>
                    <th>Type</th>
                    <th>Equipment</th>
                    <th>Quantity</th>
                    <th>Borrow Date</th>
                    <th>Due Date</th>
                    <th>Absolute Due-Date Gap</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                <tr>
                    <td><?= htmlspecialchars($record['borrower_no'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($record['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(ucfirst($record['borrower_type']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($record['borrow_id'] === null ? 'No equipment borrowed' : $record['equipment_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $record['quantity'] === null ? '—' : (int)$record['quantity'] ?></td>
                    <td><?= htmlspecialchars($record['borrow_date'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($record['due_date'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $record['days_difference'] === null ? '—' : (int)$record['days_difference'] . ' days' ?></td>
                    <td><?= htmlspecialchars($record['status_check'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">No records match these filters. Change or reset the filters to see results.</p>
        <?php endif; ?>
    </div>

    <div class="card" id="records">
        <h2>Borrowing Transactions</h2>
        <?php if ($transactions): ?>
        <table>
            <thead>
                <tr>
                    <th>Transaction</th>
                    <th>Borrower No.</th>
                    <th>Full Name</th>
                    <th>Borrow Date</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Borrowed Items</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $transaction):
                    $transaction_json = json_encode($transaction, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                ?>
                <tr>
                    <td>#<?= (int)$transaction['borrow_id'] ?></td>
                    <td><?= htmlspecialchars($transaction['borrower_no'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($transaction['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($transaction['borrow_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($transaction['due_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?= htmlspecialchars(ucfirst($transaction['status']), ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($transaction['status'] === 'borrowed' && $transaction['due_date'] < date('Y-m-d')): ?>
                            <span class="badge badge-overdue">Past Due</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($transaction['items']): ?>
                            <ul>
                                <?php foreach ($transaction['items'] as $item): ?>
                                    <li><?= htmlspecialchars($item['equipment_name'], ENT_QUOTES, 'UTF-8') ?> &times; <?= (int)$item['quantity'] ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            No items
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-group">
                            <button type="button" class="btn-edit" data-transaction="<?= htmlspecialchars($transaction_json, ENT_QUOTES, 'UTF-8') ?>" onclick="editTransaction(this)">Edit</button>
                            <form method="post" class="inline-form" onsubmit="return confirm('Delete this transaction and all of its borrowed items?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete_transaction">
                                <input type="hidden" name="borrow_id" value="<?= (int)$transaction['borrow_id'] ?>">
                                <button type="submit" class="delete">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">No borrowing transactions have been created yet.</p>
        <?php endif; ?>
    </div>
    </section>

    <section class="module-panel" id="module-equipment" role="tabpanel" aria-labelledby="tab-equipment" data-module-panel="equipment" hidden>
    <hr id="equipment">
    <h1>Equipment Management</h1>

    <div class="card">
        <h2>Add Equipment</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create_equipment">
            <div class="form-grid equipment-fields">
                <label class="field">Property number <input type="text" name="property_no" maxlength="20" placeholder="EQ-0000" pattern="EQ-[0-9]{4}" required></label>
                <label class="field">Equipment name <input type="text" name="equipment_name" maxlength="100" required></label>
                <label class="field">Category (optional) <input type="text" name="category" maxlength="50" list="category-options"></label>
                <label class="field">Initial available quantity <input type="number" name="quantity_available" min="0" required></label>
            </div>
            <datalist id="category-options">
                <?php foreach ($distinct_categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <button type="submit" class="form-button-full">Add Equipment</button>
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
                    <th>Category</th>
                    <th>Qty Available</th>
                    <th>Stock Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$equipment_list): ?>
                    <tr><td colspan="7">No equipment has been added yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($equipment_list as $eq): ?>
                <tr>
                    <td><?= (int)$eq['equipment_id'] ?></td>
                    <td><?= htmlspecialchars($eq['property_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($eq['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($eq['category'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)$eq['quantity_available'] ?></td>
                    <td><?= htmlspecialchars($eq['availability_status'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <div class="action-group">
                            <button type="button" class="btn-edit" 
                                onclick="fillEquipmentUpdate('<?= (int)$eq['equipment_id'] ?>', '<?= htmlspecialchars($eq['property_no'], ENT_QUOTES) ?>', '<?= htmlspecialchars($eq['equipment_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($eq['category'] ?? '', ENT_QUOTES) ?>', '<?= (int)$eq['quantity_available'] ?>')">
                                Edit
                            </button>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
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
        <p class="report-note">Choose <strong>Edit</strong> in the equipment inventory to load a record into this form.</p>
        <form method="post" id="equipment-update-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update_equipment">
            <div class="form-grid update-fields">
                <label class="field">Equipment ID <input type="number" name="equipment_id" id="upd_eq_id" readonly required></label>
                <label class="field">Property number <input type="text" name="property_no" id="upd_eq_prop" maxlength="20" placeholder="EQ-0000" pattern="EQ-[0-9]{4}" required></label>
                <label class="field">Equipment name <input type="text" name="equipment_name" id="upd_eq_name" maxlength="100" required></label>
                <label class="field">Category (optional) <input type="text" name="category" id="upd_eq_category" maxlength="50" list="category-options"></label>
                <label class="field">Available quantity <input type="number" name="quantity_available" id="upd_eq_qty" min="0" required></label>
            </div>
            <button type="submit" class="form-button-full button-success">Update Equipment</button>
        </form>
    </div>
    </section>

    <section class="module-panel" id="module-reports" role="tabpanel" aria-labelledby="tab-reports" data-module-panel="reports" hidden>
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
        <p class="report-note">Source: view <code>vw_equipment_usage</code> &mdash; LEFT JOIN with COUNT()/SUM() aggregation and an ordered CASE classification: more than 5 available = Available, 1&ndash;5 = Low Stock, and 0 = Out of Stock.</p>
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

    <div class="card">
        <h2>CASE Classification Checks</h2>
        <p class="report-note">Read-only SQL checks cover inventory thresholds and transaction-status boundaries. Overdue takes precedence for an explicitly overdue transaction or a borrowed transaction past its due date; due today is not overdue.</p>
        <table>
            <thead><tr><th>Classification</th><th>Scenario</th><th>Expected</th><th>Actual</th><th>Result</th></tr></thead>
            <tbody>
                <?php foreach ($case_classification_tests as $test): ?>
                <tr>
                    <td><?= htmlspecialchars($test['category'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($test['scenario'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($test['expected'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($test['actual'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge <?= $test['passed'] === true || $test['passed'] === 't' ? 'badge-ontime' : 'badge-overdue' ?>"><?= $test['passed'] === true || $test['passed'] === 't' ? 'PASS' : 'FAIL' ?></span></td>
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
        <p class="report-note">Source: <code>DATE_TRUNC()</code>/<code>EXTRACT()</code> grouped by month with <code>TO_CHAR()</code> formatting. Returned and Active counts use filtered aggregates, <code>COUNT(*) FILTER (WHERE ...)</code>.</p>
        <table>
            <thead><tr><th>Year</th><th>Month</th><th>Total Borrowings</th><th>Returned</th><th>Still Active</th></tr></thead>
            <tbody>
                <?php foreach ($monthly_report as $r): ?>
                <tr>
                    <td><?= (int)($r['yr'] ?? 0) ?></td>
                    <td><?= htmlspecialchars($r['month_label'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['total_borrows'] ?? 0) ?></td>
                    <td><?= (int)($r['returned_count'] ?? 0) ?></td>
                    <td><?= (int)($r['active_count'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Transaction Status Summary: GROUP BY on status -->
    <div class="card">
        <h2>Transaction Status Summary</h2>
        <p class="report-note">Source: <code>GROUP BY</code> on transaction status. Every allowed status is listed, including statuses with zero transactions.</p>
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
        <p class="report-note">Source: <code>GROUP BY borrower_type</code> groups transactions by borrower type. <code>WHERE</code> filters individual rows before grouping; <code>HAVING COUNT(DISTINCT borrow_id) &gt;= 2</code> filters the grouped results to borrower types with two or more transactions. <code>STRING_AGG(DISTINCT ...)</code> lists the equipment each type has borrowed.</p>
        <?php if ($grouped_analysis): ?>
        <table>
            <thead><tr><th>Borrower Type</th><th>Transactions</th><th>Total Items Borrowed</th><th>Equipment Used</th></tr></thead>
            <tbody>
                <?php foreach ($grouped_analysis as $r): ?>
                <tr>
                    <td><?= htmlspecialchars(ucfirst($r['borrower_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['transaction_count'] ?? 0) ?></td>
                    <td><?= (int)($r['total_items'] ?? 0) ?></td>
                    <td><?= htmlspecialchars($r['equipment_used'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
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
        <p class="report-note">All-time borrowing history, including returned transactions. Count is distinct borrowing transactions; total is quantity borrowed; average, minimum, and maximum are per transaction. The average divides total quantity by transaction count, guarded with <code>NULLIF()</code> so equipment with no history shows a dash instead of a divide-by-zero error. Equipment with no borrowing history shows 0 transactions and total quantity, with dashes for undefined per-transaction values.</p>
        <table>
            <thead>
                <tr><th>Property No.</th><th>Equipment</th><th>Borrow Transactions</th><th>Total Qty Borrowed</th><th>Avg Qty / Transaction</th><th>Min Qty / Transaction</th><th>Max Qty / Transaction</th></tr>
            </thead>
            <tbody>
                <?php foreach ($equip_aggregates as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['property_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['times_borrowed'] ?? 0) ?></td>
                    <td><?= (int)($r['total_qty'] ?? 0) ?></td>
                    <td><?= $r['avg_qty'] === null ? '—' : htmlspecialchars((string)$r['avg_qty'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $r['min_qty'] === null ? '—' : (int)$r['min_qty'] ?></td>
                    <td><?= $r['max_qty'] === null ? '—' : (int)$r['max_qty'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Active vs overdue borrower sets: UNION ALL vs UNION -->
    <div class="card">
        <h2>Active vs. Overdue Borrowers: Set Operations</h2>
        <p class="report-note">Both queries return the same borrower number and name columns. The first set contains borrowers with active transactions; the second contains borrowers with overdue transactions. A borrower in both sets appears twice with <code>UNION ALL</code> and once with <code>UNION</code>.</p>
        <h3><code>UNION ALL</code> <span class="report-note">(<?= count($activity_union_all) ?> rows; keeps duplicates)</span></h3>
        <?php if ($activity_union_all): ?>
        <table>
            <thead><tr><th>Borrower No.</th><th>Borrower</th></tr></thead>
            <tbody>
                <?php foreach ($activity_union_all as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['borrower_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['borrower'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">No active or overdue borrowers.</p>
        <?php endif; ?>
        <h3><code>UNION</code> <span class="report-note">(<?= count($activity_union) ?> rows; removes duplicate rows)</span></h3>
        <?php if ($activity_union): ?>
        <table>
            <thead><tr><th>Borrower No.</th><th>Borrower</th></tr></thead>
            <tbody>
                <?php foreach ($activity_union as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['borrower_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['borrower'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="report-note">No active or overdue borrowers.</p>
        <?php endif; ?>
    </div>

    <!-- Week 10 function demonstration -->
    <div class="card">
        <h2>Function Demonstration</h2>
        <p class="report-note">Source: <code>fn_total_borrowed()</code> (SQL function) and <code>fn_check_equipment_availability()</code> (PL/pgSQL). The total counts every recorded borrowing-item quantity regardless of transaction status, including borrowed, returned, and overdue transactions. The availability function returns No for missing equipment, nonpositive requests, or requests above available stock.</p>
        <table>
            <thead><tr><th>Property No.</th><th>Equipment</th><th>Available</th><th>Can Lend 1?</th><th>Can Lend Available + 1?</th><th>Total Borrowed (all time)</th></tr></thead>
            <tbody>
                <?php foreach ($fn_demo as $r):
                    $can = ($r['can_lend_one'] === true || $r['can_lend_one'] === 't');
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['property_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)($r['quantity_available'] ?? 0) ?></td>
                    <td><?= $can ? 'Yes' : 'No' ?></td>
                    <td><?= ($r['can_lend_one_more_than_available'] === true || $r['can_lend_one_more_than_available'] === 't') ? 'Yes' : 'No' ?></td>
                    <td><?= (int)($r['total_borrowed_all_time'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Recent audit trail from the AFTER trigger -->
    <div class="card">
        <h2>Audit Trail (recent)</h2>
        <p class="report-note">Written automatically by the AFTER trigger <code>trg_borrow_items_audit</code> using <code>NEW</code>, <code>OLD</code> and <code>TG_OP</code>. The operator is read from the session setting <code>wk5.actor</code>.</p>
        <?php if ($audit_log): ?>
        <table>
            <thead><tr><th>#</th><th>Borrow ID</th><th>Equip. ID</th><th>Old Qty</th><th>New Qty</th><th>Action</th><th>By</th><th>When</th></tr></thead>
            <tbody>
                <?php foreach ($audit_log as $r): ?>
                <tr>
                    <td><?= (int)$r['audit_id'] ?></td>
                    <td><?= (int)$r['borrow_id'] ?></td>
                    <td><?= (int)$r['equipment_id'] ?></td>
                    <td><?= $r['old_quantity'] === null ? '-' : (int)$r['old_quantity'] ?></td>
                    <td><?= $r['new_quantity'] === null ? '-' : (int)$r['new_quantity'] ?></td>
                    <td><?= htmlspecialchars($r['action'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['changed_by'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
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
            <strong>rolled back</strong>, so no data is permanently changed. The checks cover primary-key, foreign-key,
            unique, not-null, and check constraints, along with the BEFORE INSERT/UPDATE borrowing-quantity trigger.
            Integration checks compare the SQL function's borrowed total, audited old/new values, and available stock
            across item insert, update, delete, and Borrowed/Returned status changes. Passed
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
            <p class="report-note">Self-tests unavailable — the integration checks require a registered borrower and at least one equipment item with two or more units available.</p>
        <?php endif; ?>
    </div>

    <!-- Reference queries: DISTINCT + UNION -->
    <div class="card">
        <h2>Reference Lists</h2>
        <h3>Registered Borrower Types <span class="report-note">(<code>DISTINCT</code>)</span></h3>
        <p class="report-note">
            <?= $distinct_types ? htmlspecialchars(implode(', ', array_map('ucfirst', $distinct_types)), ENT_QUOTES, 'UTF-8') : 'None' ?>
        </p>
        <h3>Equipment Categories <span class="report-note">(<code>DISTINCT</code>)</span></h3>
        <p class="report-note">
            <?= $distinct_categories ? htmlspecialchars(implode(', ', $distinct_categories), ENT_QUOTES, 'UTF-8') : 'None' ?>
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
    </section>

    <script src="wk5_database2.js"></script>
</body>
</html>