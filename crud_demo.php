<?php

$host = 'localhost';
$port = '5432';
$dbname = 'school_db';
$user = 'postgres';
$password = 'woshidaxuesheng';

try {

    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $password
    );

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $pdo->exec('SET search_path TO "week5"');

} catch (PDOException $e) {

    die('Database connection failed.');
}


// ----------------------------------------------------
// CREATE
// ----------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'create'
) {

    $stmt = $pdo->prepare(
        'INSERT INTO students_demo
            (student_no, student_name, email)
         VALUES
            (:student_no, :student_name, :email)'
    );

    $stmt->execute([
        ':student_no' => trim($_POST['student_no'] ?? ''),
        ':student_name' => trim($_POST['student_name'] ?? ''),
        ':email' => trim($_POST['email'] ?? '')
    ]);

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}


// ----------------------------------------------------
// UPDATE
// ----------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update'
) {

    $stmt = $pdo->prepare(
        'UPDATE students_demo
         SET student_no = :student_no,
             student_name = :student_name,
             email = :email
         WHERE student_id = :student_id'
    );

    $stmt->execute([
        ':student_no' => trim($_POST['student_no'] ?? ''),
        ':student_name' => trim($_POST['student_name'] ?? ''),
        ':email' => trim($_POST['email'] ?? ''),
        ':student_id' => (int)($_POST['student_id'] ?? 0)
    ]);

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}


// ----------------------------------------------------
// DELETE
// ----------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {

    $stmt = $pdo->prepare(
        'DELETE FROM students_demo
         WHERE student_id = :student_id'
    );

    $stmt->execute([
        ':student_id' => (int)($_POST['student_id'] ?? 0)
    ]);

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}


// ----------------------------------------------------
// READ
// ----------------------------------------------------

$stmt = $pdo->query(
    'SELECT student_id, student_no, student_name, email
     FROM students_demo
     ORDER BY student_id DESC'
);

$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Student CRUD Demo</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            max-width: 900px;
            margin: 30px auto;
            padding: 0 15px;
        }

        form {
            margin-bottom: 20px;
        }

        input,
        button {
            padding: 8px;
            margin: 3px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
        }

    </style>

</head>

<body>

    <h1>Student CRUD Demo</h1>


    <h2>Create Student</h2>

    <form method="post">

        <input
            type="hidden"
            name="action"
            value="create"
        >

        <input
            type="text"
            name="student_no"
            placeholder="Student No."
            required
        >

        <input
            type="text"
            name="student_name"
            placeholder="Student Name"
            required
        >

        <input
            type="email"
            name="email"
            placeholder="Email"
        >

        <button type="submit">
            Add Student
        </button>

    </form>


    <h2>Student Records</h2>

    <table>

        <thead>

            <tr>
                <th>ID</th>
                <th>Student No.</th>
                <th>Name</th>
                <th>Email</th>
                <th>Actions</th>
            </tr>

        </thead>

        <tbody>

        <?php foreach ($students as $student): ?>

            <tr>

                <td>
                    <?= (int)$student['student_id'] ?>
                </td>

                <td>
                    <?=
                        htmlspecialchars(
                            $student['student_no'],
                            ENT_QUOTES,
                            'UTF-8'
                        )
                    ?>
                </td>

                <td>
                    <?=
                        htmlspecialchars(
                            $student['student_name'],
                            ENT_QUOTES,
                            'UTF-8'
                        )
                    ?>
                </td>

                <td>
                    <?=
                        htmlspecialchars(
                            $student['email'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        )
                    ?>
                </td>

                <td>

                    <form
                        method="post"
                        style="display:inline"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="delete"
                        >

                        <input
                            type="hidden"
                            name="student_id"
                            value="<?= (int)$student['student_id'] ?>"
                        >

                        <button type="submit">
                            Delete
                        </button>

                    </form>

                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>


    <h2>Update Student</h2>

    <form method="post">

        <input
            type="hidden"
            name="action"
            value="update"
        >

        <input
            type="number"
            name="student_id"
            placeholder="Student ID"
            required
        >

        <input
            type="text"
            name="student_no"
            placeholder="Student No."
            required
        >

        <input
            type="text"
            name="student_name"
            placeholder="Student Name"
            required
        >

        <input
            type="email"
            name="email"
            placeholder="Email"
        >

        <button type="submit">
            Update Student
        </button>

    </form>

</body>

</html>