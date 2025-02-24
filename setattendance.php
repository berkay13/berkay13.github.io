<?php
session_start();
include('db.php');

// Redirect if not admin
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header("Location: dashboard.php");
    exit;
}

// Get the seminar ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Invalid seminar ID.");
}

$seminar_id = intval($_GET['id']);

// Fetch seminar details
$stmt = $conn->prepare("SELECT title FROM Seminars WHERE seminar_id = ?");
$stmt->bind_param("i", $seminar_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Seminar not found.");
}

$seminar = $result->fetch_assoc();

// Handle form submission to add attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_students'])) {
    $emails = isset($_POST['students']) ? preg_split('/\r\n|\r|\n/', trim($_POST['students'])) : [];

    // Insert new attendance records
    $insertStmt = $conn->prepare("INSERT IGNORE INTO SeminarAttendance (seminar_id, student_email) VALUES (?, ?)");
    foreach ($emails as $email) {
        $email = trim($email);
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) { // Validate email
            $insertStmt->bind_param("is", $seminar_id, $email);
            $insertStmt->execute();
        }
    }
    $insertStmt->close();

    // Redirect to refresh the page and avoid form resubmission
    header("Location: setattendance.php?id=" . $seminar_id . "&success=1");
    exit;
}

// Handle individual email removal
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['remove_student'])) {
    $email = $_GET['remove_student'];
    $removeStmt = $conn->prepare("DELETE FROM SeminarAttendance WHERE seminar_id = ? AND student_email = ?");
    $removeStmt->bind_param("is", $seminar_id, $email);
    $removeStmt->execute();
    $removeStmt->close();

    // Redirect to avoid repeated deletion on refresh
    header("Location: setattendance.php?id=" . $seminar_id . "&removed=1");
    exit;
}

// Fetch existing attendance for display
$stmt = $conn->prepare("SELECT student_email FROM SeminarAttendance WHERE seminar_id = ?");
$stmt->bind_param("i", $seminar_id);
$stmt->execute();
$result = $stmt->get_result();

$current_attendance = [];
while ($row = $result->fetch_assoc()) {
    $current_attendance[] = $row['student_email'];
}
$stmt->close();
$conn->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Attendance - <?php echo htmlspecialchars($seminar['title']); ?></title>
    <link rel="stylesheet" href="css/setattendance.css">
</head>
<body>
    <a href="dashboard.php" id="back-to-dashboard">Back to Dashboard</a>
    
    <h1>Set Attendance for Seminar: <?php echo htmlspecialchars($seminar['title']); ?></h1>

    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <p class="success-message">Attendance updated successfully!</p>
    <?php endif; ?>
    <?php if (isset($_GET['removed']) && $_GET['removed'] == 1): ?>
        <p class="success-message">Student removed from attendance successfully!</p>
    <?php endif; ?>

    <h2>Current Attendance</h2>
    <?php if (empty($current_attendance)): ?>
        <p>No students have been marked as attended yet.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($current_attendance as $email): ?>
                <li>
                    <?php echo htmlspecialchars($email); ?>
                    <a href="setattendance.php?id=<?php echo $seminar_id; ?>&remove_student=<?php echo urlencode($email); ?>" onclick="return confirm('Are you sure you want to remove this student?');" class="remove-student">❌</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2>Add Students</h2>
    <form method="POST">
        <label for="students">Enter Student Emails (one per line):</label>
        <textarea name="students" id="students" rows="10" cols="50" placeholder="Enter student emails here..."></textarea>
        <br><br>
        <button type="submit" name="add_students">Add Attendance</button>
    </form>
</body>
</html>
