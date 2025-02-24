<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db.php');

// Check if user is logged in and is an admin
if (!isset($_SESSION['user']) || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit();
}

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'add';
$seminar_id = isset($_GET['id']) ? $_GET['id'] : null;
$is_edit = $mode === 'edit';

// Initialize seminar data with default values, including attendance_weight
$seminar = [
    'title' => '',
    'abstract' => '',
    'time_start' => '',
    'time_end' => '',
    'place' => '',
    'disciplines' => '',
    'tags' => '',
    'types' => [],
    'is_visible' => 1,
    'image' => '',
    'speaker' => '',
    'speaker_bio' => '',
    'is_physical' => 1, // Default to physical
    'is_online' => 0,
    'online_url' => '',
    'attendance_weight' => 1 // Default attendance weight
];

// Fetch all available options
$available_types = $conn->query("SELECT * FROM SeminarTypes")->fetch_all(MYSQLI_ASSOC);
$available_disciplines = $conn->query("SELECT * FROM SeminarDisciplines")->fetch_all(MYSQLI_ASSOC);
$available_tags = $conn->query("SELECT * FROM SeminarTags")->fetch_all(MYSQLI_ASSOC);

// Fetch seminar details if in edit mode
if ($is_edit && $seminar_id) {
    $stmt = $conn->prepare("SELECT * FROM Seminars WHERE seminar_id = ?");
    $stmt->bind_param('i', $seminar_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Seminar not found, redirect or handle error
        header("Location: dashboard.php?error=Seminar not found");
        exit();
    }

    $seminar = $result->fetch_assoc();

    // Fetch disciplines
    $stmt = $conn->prepare("
        SELECT sd.name
        FROM SeminarDisciplinesMapping sdm
        JOIN SeminarDisciplines sd ON sdm.discipline_id = sd.id
        WHERE sdm.seminar_id = ?");
    $stmt->bind_param('i', $seminar_id);
    $stmt->execute();
    $seminar['disciplines'] = implode(', ', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name'));

    // Fetch tags
    $stmt = $conn->prepare("
        SELECT st.name
        FROM SeminarTagsMapping stm
        JOIN SeminarTags st ON stm.tag_id = st.id
        WHERE stm.seminar_id = ?");
    $stmt->bind_param('i', $seminar_id);
    $stmt->execute();
    $seminar['tags'] = implode(', ', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name'));

    // Fetch types
    $stmt = $conn->prepare("SELECT type_id FROM SeminarTypeMapping WHERE seminar_id = ?");
    $stmt->bind_param('i', $seminar_id);
    $stmt->execute();
    $seminar['types'] = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'type_id');
}

// Function to sanitize the seminar title for filename
function sanitizeFileName($string) {
    // Replace spaces with underscores
    $string = str_replace(' ', '_', $string);
    // Remove any character that is not alphanumeric, underscore, or dash
    return preg_replace('/[^A-Za-z0-9_\-]/', '', $string);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $title = trim($_POST['title']);
    $abstract = trim($_POST['abstract']);
    $time_start = !empty($_POST['time_start']) ? $_POST['time_start'] : null;
    $time_end   = !empty($_POST['time_end']) ? $_POST['time_end'] : null;
    $place = trim($_POST['place']);
    $speaker = trim($_POST['speaker']);
    $speaker_bio = trim($_POST['speaker_bio']);
    $disciplines = $_POST['disciplines'] ?? [];
    $tags = $_POST['tags'] ?? [];
    $selected_types = $_POST['types'] ?? [];
    $is_visible = isset($_POST['is_visible']) ? 1 : 0;

    // Retrieve seminar_type and online_url from POST data
    $seminar_type = $_POST['seminar_type'];
    $online_url = trim($_POST['online_url']) ?? '';

    // Retrieve and validate attendance_weight
    if (isset($_POST['attendance_weight']) && is_numeric($_POST['attendance_weight'])) {
        $attendance_weight = (int)$_POST['attendance_weight'];
        if ($attendance_weight < 0) {
            $attendance_weight = 0; // Ensure non-negative
        }
    } else {
        $attendance_weight = 1; // Default value
    }

    // Set is_physical and is_online based on seminar_type
    switch($seminar_type){
        case 'physical':
            $is_physical = 1;
            $is_online = 0;
            $online_url = ''; // Clear online_url for physical seminars
            break;
        case 'online':
            $is_physical = 0;
            $is_online = 1;
            break;
        case 'hybrid':
            $is_physical = 1;
            $is_online = 1;
            break;
        default:
            $is_physical = 1;
            $is_online = 0;
            $online_url = '';
    }

    // Initialize image_path
    $image_path = $is_edit ? $seminar['image'] : 'uploads/SUseminar.jpg'; // Set default image if no image is uploaded

    // Handle file upload
    if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
        $target_dir = "uploads/";

        // Sanitize the seminar title for the filename
        $sanitized_title = sanitizeFileName($title);

        // Get current date and time
        $current_date = date('YmdHis'); // Format: YYYYMMDDHHMMSS

        // Get the original file extension
        $original_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));

        // Create a unique filename
        $unique_filename = $sanitized_title . '_' . $current_date . '.' . $original_extension;

        // Set the target file path
        $target_file = $target_dir . $unique_filename;

        // Validate the file type
        $allowed_types = ['jpg', 'jpeg'];
        if (in_array($original_extension, $allowed_types)) {
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                $image_path = $target_file;
            } else {
                $error = "Error uploading your file.";
            }
        } else {
            $error = "Invalid file type. Only JPEG images are allowed.";
        }
    }

    // Proceed only if there are no upload errors
    if (!isset($error)) {
        // Insert or update seminar
        if ($is_edit) {
            // Updated UPDATE Statement to Include attendance_weight
            $stmt = $conn->prepare("
                UPDATE Seminars 
                SET title = ?, abstract = ?, time_start = ?, time_end = ?, place = ?, speaker = ?, speaker_bio = ?, image = ?, is_visible = ?, is_physical = ?, is_online = ?, online_url = ?, attendance_weight = ?
                WHERE seminar_id = ?");
            // Bind the new parameters including is_physical, is_online, online_url, attendance_weight
            $stmt->bind_param('ssssssssiiisii', $title, $abstract, $time_start, $time_end, $place, $speaker, $speaker_bio, $image_path, $is_visible, $is_physical, $is_online, $online_url, $attendance_weight, $seminar_id);
        } else {
            // Updated INSERT Statement to Include attendance_weight
            // Note the changed bind parameter string: the last parameter (created_by) is now bound as a string.
            $stmt = $conn->prepare("
                INSERT INTO Seminars (title, abstract, time_start, time_end, place, speaker, speaker_bio, image, is_visible, is_physical, is_online, online_url, attendance_weight, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssssssiiisis', $title, $abstract, $time_start, $time_end, $place, $speaker, $speaker_bio, $image_path, $is_visible, $is_physical, $is_online, $online_url, $attendance_weight, $_SESSION['user']);
        }
        $stmt->execute();

        // Get the seminar_id for new entries
        if (!$is_edit) {
            $seminar_id = $conn->insert_id;
        }

        // Clear existing mappings if editing
        if ($is_edit) {
            $mappings = [
                "SeminarDisciplinesMapping" => "discipline_id",
                "SeminarTagsMapping" => "tag_id",
                "SeminarTypeMapping" => "type_id"
            ];

            foreach ($mappings as $table => $column) {
                $stmt = $conn->prepare("DELETE FROM $table WHERE seminar_id = ?");
                $stmt->bind_param('i', $seminar_id);
                $stmt->execute();
            }
        }

        // Insert new discipline mappings
        if (!empty($disciplines)) {
            $stmt = $conn->prepare("INSERT INTO SeminarDisciplinesMapping (seminar_id, discipline_id) VALUES (?, ?)");
            foreach ($disciplines as $discipline_id) {
                $stmt->bind_param('ii', $seminar_id, $discipline_id);
                $stmt->execute();
            }
        }

        // Insert new tag mappings
        if (!empty($tags)) {
            $stmt = $conn->prepare("INSERT INTO SeminarTagsMapping (seminar_id, tag_id) VALUES (?, ?)");
            foreach ($tags as $tag_id) {
                $stmt->bind_param('ii', $seminar_id, $tag_id);
                $stmt->execute();
            }
        }

        // Insert new type mappings
        if (!empty($selected_types)) {
            $stmt = $conn->prepare("INSERT INTO SeminarTypeMapping (seminar_id, type_id) VALUES (?, ?)");
            foreach ($selected_types as $type_id) {
                $stmt->bind_param('ii', $seminar_id, $type_id);
                $stmt->execute();
            }
        }

        // Close the statement and connection
        $stmt->close();
        $conn->close();

        // Redirect to dashboard
        header("Location: dashboard.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit ? 'Edit' : 'Add'; ?> Seminar</title>
    <link rel="stylesheet" href="css/edit_seminar.css">
    <style>
        /* Basic styling for error messages */
        .error {
            color: red;
        }
    </style>
    <script>
        function toggleOnlineURL() {
            const seminarType = document.querySelector('input[name="seminar_type"]:checked').value;
            const onlineURLField = document.getElementById('online_url_field');
            if (seminarType === 'online' || seminarType === 'hybrid') {
                onlineURLField.style.display = 'block';
            } else {
                onlineURLField.style.display = 'none';
            }
        }

        window.addEventListener('DOMContentLoaded', (event) => {
            toggleOnlineURL(); // Initialize on page load

            const seminarTypeRadios = document.querySelectorAll('input[name="seminar_type"]');
            seminarTypeRadios.forEach(radio => {
                radio.addEventListener('change', toggleOnlineURL);
            });
        });
    </script>
</head>
<body>
    <?php if (isset($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    <form method="post" action="" enctype="multipart/form-data">
        <h2><?php echo $is_edit ? 'Edit' : 'Add'; ?> Seminar</h2>

        <!-- Seminar Details -->
        <label for="title">Title:</label><br>
        <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($seminar['title']); ?>" required><br>
        
        <label for="abstract">Abstract:</label><br>
        <textarea id="abstract" name="abstract"><?php echo htmlspecialchars($seminar['abstract']); ?></textarea><br>
        
        <label for="speaker">Speaker:</label><br>
        <input type="text" id="speaker" name="speaker" value="<?php echo htmlspecialchars($seminar['speaker']); ?>"><br>

        <label for="speaker_bio">Speaker Biography:</label><br>
        <textarea id="speaker_bio" name="speaker_bio"><?php echo htmlspecialchars($seminar['speaker_bio']); ?></textarea><br>
        
        <label for="time_start">Start Time:</label><br>
        <input type="datetime-local" id="time_start" name="time_start" 
               value="<?php echo htmlspecialchars($seminar['time_start'] ? date('Y-m-d\TH:i', strtotime($seminar['time_start'])) : ''); ?>"><br>

        <label for="time_end">End Time:</label><br>
        <input type="datetime-local" id="time_end" name="time_end" 
               value="<?php echo htmlspecialchars($seminar['time_end'] ? date('Y-m-d\TH:i', strtotime($seminar['time_end'])) : ''); ?>"><br>

        <label for="place">Place:</label><br>
        <input type="text" id="place" name="place" value="<?php echo htmlspecialchars($seminar['place']); ?>"><br>
        
        <!-- Seminar Image -->
        <label for="image">Upload Image (JPEG only):</label><br>
        <?php if (!empty($seminar['image'])): ?>
            <div>
                <p>Current Image: <?php echo htmlspecialchars(basename($seminar['image'])); ?></p>
                <img src="<?php echo htmlspecialchars($seminar['image']); ?>" alt="Current Seminar Image" style="max-width: 200px; max-height: 200px;">
            </div>
        <?php endif; ?>
        <input type="file" id="image" name="image" accept="image/jpeg"><br>

        <!-- Seminar Type -->
        <label>Seminar Type:</label><br>
        <label>
            <input type="radio" name="seminar_type" value="physical" <?php echo ($seminar['is_physical'] && !$seminar['is_online']) ? 'checked' : ''; ?> required>
            Physical
        </label><br>
        <label>
            <input type="radio" name="seminar_type" value="online" <?php echo (!$seminar['is_physical'] && $seminar['is_online']) ? 'checked' : ''; ?>>
            Online
        </label><br>
        <label>
            <input type="radio" name="seminar_type" value="hybrid" <?php echo ($seminar['is_physical'] && $seminar['is_online']) ? 'checked' : ''; ?>>
            Hybrid
        </label><br>

        <div id="online_url_field" style="display: none;">
            <label for="online_url">Online URL:</label><br>
            <input type="url" id="online_url" name="online_url" value="<?php echo htmlspecialchars($seminar['online_url']); ?>"><br>
        </div>
        
        <!-- Attendance Weight -->
        <label for="attendance_weight">Attendance Weight:</label><br>
        <input type="number" id="attendance_weight" name="attendance_weight" value="<?php echo htmlspecialchars($seminar['attendance_weight']); ?>"><br>

        <!-- Disciplines -->
        <label>Disciplines:</label><br>
        <div id="disciplines-container">
            <?php if (is_array($available_disciplines)): ?>
                <?php foreach ($available_disciplines as $discipline): ?>
                    <label>
                        <input type="checkbox" name="disciplines[]" value="<?php echo $discipline['id']; ?>" 
                               <?php echo in_array($discipline['name'], explode(', ', $seminar['disciplines'])) ? 'checked' : ''; ?>>
                        <?php echo htmlspecialchars($discipline['name']); ?>
                    </label><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Tags -->
        <label>Tags:</label><br>
        <div id="tags-container">
            <?php if (is_array($available_tags)): ?>
                <?php foreach ($available_tags as $tag): ?>
                    <label>
                        <input type="checkbox" name="tags[]" value="<?php echo $tag['id']; ?>" 
                               <?php echo in_array($tag['name'], explode(', ', $seminar['tags'])) ? 'checked' : ''; ?>>
                        <?php echo htmlspecialchars($tag['name']); ?>
                    </label><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Types -->
        <label>Types:</label><br>
        <div id="types-container">
            <?php if (is_array($available_types)): ?>
                <?php foreach ($available_types as $type): ?>
                    <label>
                        <input type="checkbox" name="types[]" value="<?php echo $type['id']; ?>" 
                               <?php echo is_array($seminar['types']) && in_array($type['id'], $seminar['types']) ? 'checked' : ''; ?>>
                        <?php echo htmlspecialchars($type['name']); ?>
                    </label><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Visibility -->
        <div class="checkbox-container">
            <label for="is_visible" id="visible_label">Visible:</label>
            <input type="checkbox" id="is_visible" name="is_visible" value="1" <?php echo $seminar['is_visible'] ? 'checked' : ''; ?>>
        </div>

        <input type="submit" value="<?php echo $is_edit ? 'Update' : 'Add'; ?> Seminar">
    </form>
    <a href="dashboard.php" id="back-to-dashboard">Back to Dashboard</a>
</body>
</html>
